<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared by CaptureHostedPaymentRequestHandler and CaptureAliasPaymentRequestHandler, the two
 * sibling capture paths that each resolve/validate their inputs and build a
 * CommonFieldsDto/BrowserDto/CustomerDto for the Unified API the same way, differing only in the
 * final HostedFieldDto/PaymentDto they build around it.
 */
final class PaymentCaptureContextBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        // Builds the successUrl/cancelUrl sent to the Unified API, so a 3DS/SCA challenge returns
        // the shopper to this same /pay page afterwards.
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')] // @phpstan-ignore-line
        private UrlProviderInterface $afterPayUrlProvider,
        private OrderAddressDtoCreator $orderAddressDtoCreator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{0: string, 1: string} accountId, submerchantExternalId
     */
    public function resolveGatewayCredentials(PaymentMethodInterface $method): array
    {
        return GatewayCredentialsResolver::resolve($method);
    }

    public function buildCommonFields(
        string $accountId,
        int $amount,
        string $currencyCode,
        string $submerchantExternalId,
        PaymentRequestInterface $paymentRequest,
        ?OrderInterface $order,
    ): CommonFieldsDto {
        $orderId = PaymentOrderIdResolver::resolve($order, $paymentRequest->getPayment()->getId());
        $common = new CommonFieldsDto($accountId, $amount, \strtoupper($currencyCode), $orderId, $submerchantExternalId);
        $common->description = $this->resolveDescription($order);
        // Confirmed with PayPlug: this field has no effect on their side regardless of value for
        // Hosted Fields/UPC — the only working notification path is the static Cockpit-configured
        // Receiver at /payplug/v2/ipn (see UnifiedApiIpnAction's docblock). Set anyway to keep the
        // DTO's contract intact rather than leaving the field unset.
        $common->notificationUrl = $this->urlGenerator->generate(
            'sylius_payment_request_notify',
            ['hash' => (string) $paymentRequest->getHash()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $successUrl = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $common->successUrl = $successUrl;
        $common->cancelUrl = $successUrl . '?' . http_build_query(['status' => 'canceled']);
        if (null !== $order) {
            $common->billing = $this->orderAddressDtoCreator->createBilling($order);
            $common->shipping = $this->orderAddressDtoCreator->createShipping($order);
        }

        return $common;
    }

    public function buildBrowserDto(): ?BrowserDto
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request
            ? new BrowserDto(
                $request->getClientIp() ?? '',
                $request->headers->get('referer', '') ?? '',
                $request->headers->get('User-Agent', '') ?? '',
            )
            : null;
    }

    public function resolvePaymentMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        return $method;
    }

    /** @return array{0: int, 1: string} */
    public function resolveAmountAndCurrency(PaymentInterface $payment): array
    {
        $amount = $payment->getAmount();
        $currencyCode = $payment->getCurrencyCode();
        if (null === $amount || null === $currencyCode) {
            throw new \LogicException('Payment amount or currency is not set.');
        }

        return [$amount, $currencyCode];
    }

    /**
     * Extracted from a payment-creation response body rather than PaymentOutput itself (which
     * carries no such fields) — needed by StatusHostedPaymentRequestHandler's 3DS polling
     * fallback and by UnifiedApiIpnAction::resolveHostedFieldsPayment(), which looks these ids up
     * on Payment::details. Shared by both capture handlers (token and alias) since either flow's
     * response can carry a pending 3DS challenge that only the webhook/polling path resolves.
     *
     * @return array{hosted_fields_payment_id?: string, hosted_fields_operation_id?: string}
     */
    public function resolveHostedFieldsIds(string $body): array
    {
        $decoded = \json_decode($body, true);
        $paymentId = \is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $operationIds = \is_array($decoded) ? ($decoded['operationIds'] ?? null) : null;
        $operationId = \is_array($operationIds) ? ($operationIds[0] ?? null) : null;

        // Each id is stored independently — a response carrying only one of the two (e.g. no
        // operationIds yet) must not also drop the other, since dropping hosted_fields_operation_id
        // silently disables the card-metadata enrichment in PayplugCardPersister::persist().
        $result = [];
        if (\is_string($paymentId) && '' !== $paymentId) {
            $result['hosted_fields_payment_id'] = $paymentId;
        }
        if (\is_string($operationId) && '' !== $operationId) {
            $result['hosted_fields_operation_id'] = $operationId;
        }

        return $result;
    }

    public function buildCustomerDto(?OrderInterface $order): CustomerDto
    {
        $customer = $order?->getCustomer();
        if (null === $customer || null === $customer->getEmail()) {
            throw new \LogicException('Customer email is not set for the payment.');
        }

        return new CustomerDto(ResourceIdentifier::toString($customer->getId()), $customer->getEmail());
    }

    /**
     * Falls back to the billing address's own full name, then the customer's, so a
     * paymentMethod.details.fullName the Unified API requires whenever saveFutureUsage is
     * requested isn't left unset just because the billing address itself has none set.
     */
    public function resolveFullNameForCardDetails(?OrderInterface $order): ?string
    {
        $fullName = $order?->getBillingAddress()?->getFullName();
        if (null !== $fullName && '' !== $fullName) {
            return $fullName;
        }

        $fullName = $order?->getCustomer()?->getFullName();

        return null !== $fullName && '' !== $fullName ? $fullName : null;
    }

    // Identifies the order's own product for PayPlug's back office; falls back to a generic
    // integration/version string only when there's no order or no line item to name (e.g. this
    // is also used by the pay-with-an-existing-alias flow, which shares the same order shape).
    private function resolveDescription(?OrderInterface $order): ?string
    {
        $firstItemOrFalse = $order?->getItems()->first();
        $firstItem = false !== $firstItemOrFalse ? $firstItemOrFalse : null;

        return null !== $firstItem ? $firstItem->getProductName() : IntegrationDescriptionProvider::build();
    }
}
