<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\HostedPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Output\HostedPaymentOutput;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final class CaptureHostedPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private HostedPaymentCreatorInterface $hostedPaymentCreator,
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')] // @phpstan-ignore-line
        private UrlProviderInterface $afterPayUrlProvider,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private IOrderStateMutator $orderStateMutator,
    ) {
    }

    public function __invoke(CaptureHostedPaymentRequest $captureHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($captureHostedPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $details = $payment->getDetails();

        $method = $this->resolvePaymentMethod($payment);
        $hfToken = $this->resolveHostedFieldsToken($details);
        $amountAndCurrency = $this->resolveAmountAndCurrency($payment);
        $credentials = $this->resolveGatewayCredentials($method);

        try {
            $dto = $this->buildHostedFieldDto(
                $paymentRequest,
                $payment,
                $details,
                $hfToken,
                $amountAndCurrency,
                $credentials,
            );

            $this->logger->debug('[PayPlug debug] Unified API hosted payment request payload.', [
                'payload' => $dto->createPayloadBody(),
            ]);

            $output = $this->hostedPaymentCreator->createHostedPayment($dto);
        } catch (ApiException | InvalidHostedFieldException | \LogicException $e) {
            $this->failPaymentRequest($paymentRequest, $payment, $e);

            return;
        }

        $payment->setDetails([
            ...$details,
            'hosted_fields_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ...$this->resolveHostedFieldsIds($output->body),
        ]);

        $this->applyOutcome($paymentRequest, $payment, $output);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function resolvePaymentMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        return $method;
    }

    /** @param mixed[] $details */
    private function resolveHostedFieldsToken(array $details): string
    {
        $hfToken = $details['hosted_fields_token'] ?? null;
        if (!\is_string($hfToken) || '' === $hfToken) {
            throw new \LogicException('No hosted fields token found on the payment.');
        }

        return $hfToken;
    }

    /**
     * Extracted from the creation response body rather than HostedPaymentOutput itself (which
     * carries no such fields) — needed by StatusHostedPaymentRequestHandler's 3DS polling
     * fallback and consistent with the ids the webhook path resolves against.
     *
     * @return array{hosted_fields_payment_id?: string, hosted_fields_operation_id?: string}
     */
    private function resolveHostedFieldsIds(string $body): array
    {
        $decoded = \json_decode($body, true);
        $paymentId = \is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $operationIds = \is_array($decoded) ? ($decoded['operationIds'] ?? null) : null;
        $operationId = \is_array($operationIds) ? ($operationIds[0] ?? null) : null;

        if (!\is_string($paymentId) || '' === $paymentId || !\is_string($operationId) || '' === $operationId) {
            return [];
        }

        return [
            'hosted_fields_payment_id' => $paymentId,
            'hosted_fields_operation_id' => $operationId,
        ];
    }

    /** @return array{0: int, 1: string} */
    private function resolveAmountAndCurrency(PaymentInterface $payment): array
    {
        $amount = $payment->getAmount();
        $currencyCode = $payment->getCurrencyCode();
        if (null === $amount || null === $currencyCode) {
            throw new \LogicException('Payment amount or currency is not set.');
        }

        return [$amount, $currencyCode];
    }

    /** @return array{0: string, 1: string} */
    private function resolveGatewayCredentials(PaymentMethodInterface $method): array
    {
        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $accountId = $gatewayConfig[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null;
        $submerchantExternalId = $gatewayConfig[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null;
        if (!\is_string($accountId) || '' === $accountId || !\is_string($submerchantExternalId) || '' === $submerchantExternalId) {
            throw new \LogicException('Hosted Fields account id or submerchant id is not configured for this payment method.');
        }

        return [$accountId, $submerchantExternalId];
    }

    /**
     * @param mixed[] $details
     * @param array{0: int, 1: string} $amountAndCurrency
     * @param array{0: string, 1: string} $credentials
     */
    private function buildHostedFieldDto(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        array $details,
        string $hfToken,
        array $amountAndCurrency,
        array $credentials,
    ): HostedFieldDto {
        [$amount, $currencyCode] = $amountAndCurrency;
        [$accountId, $submerchantExternalId] = $credentials;

        $order = $payment->getOrder();
        $orderId = $order?->getNumber() ?? self::idToString($payment->getId());
        $firstItemOrFalse = $order?->getItems()->first();
        $firstItem = false !== $firstItemOrFalse ? $firstItemOrFalse : null;

        $common = new CommonFieldsDto($accountId, $amount, \strtoupper($currencyCode), $orderId, $submerchantExternalId);
        $common->description = null !== $firstItem ? $firstItem->getProductName() : null;
        // Fixed, parameter-less URL rather than the per-payment-request hashed route:
        // UnifiedApiIpnAction already resolves the target Payment generically from the webhook
        // body's own "id" (via PaymentRepositoryInterface::findOneByPayPlugPaymentId(), matching
        // hosted_fields_payment_id), so a stable URL is enough — and it's the only shape PayPlug's
        // Unified API notifier Receiver (configured once in Cockpit) can target at all.
        $common->notificationUrl = $this->urlGenerator->generate(
            'payplug_sylius_unified_api_ipn',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $successUrl = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $common->successUrl = $successUrl;
        $common->cancelUrl = $successUrl . '?' . http_build_query(['status' => 'canceled']);

        $selectedBrand = $details['hosted_fields_selected_brand'] ?? null;
        $paymentMethodDetails = \is_string($selectedBrand) && '' !== $selectedBrand
            ? ['details' => ['selectedBrand' => $selectedBrand]]
            : null;

        return new HostedFieldDto(
            $common,
            $hfToken,
            $this->buildBrowserDto(),
            $this->buildCustomerDto($order),
            $paymentMethodDetails,
        );
    }

    private function buildCustomerDto(?OrderInterface $order): CustomerDto
    {
        $customer = $order?->getCustomer();
        if (null === $customer || null === $customer->getEmail()) {
            throw new \LogicException('Customer email is not set for the payment.');
        }

        return new CustomerDto(self::idToString($customer->getId()), $customer->getEmail());
    }

    private function buildBrowserDto(): ?BrowserDto
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        return new BrowserDto(
            $request->getClientIp() ?? '',
            $request->headers->get('referer', '') ?? '',
            $request->headers->get('User-Agent', '') ?? '',
        );
    }

    private function failPaymentRequest(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        \Throwable $e,
    ): void {
        $this->logger->error('[PayPlug][UPC] Hosted payment creation failed.', [
            'sylius_payment_id' => $payment->getId(),
            'error' => $e->getMessage(),
        ]);
        $paymentRequest->setResponseData(['error' => $e->getMessage()]);
        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function applyOutcome(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        HostedPaymentOutput $output,
    ): void {
        if (null !== $output->redirectUrl) {
            $paymentRequest->setResponseData(['redirect_url' => $output->redirectUrl]);

            return;
        }

        if (null !== $output->redirectHtml) {
            // The Unified API's "recommended for web" 3DS shape: a self-submitting HTML form
            // (rendered as-is by CaptureHttpResponseProvider) rather than a redirect target. Like
            // the redirect_url branch above, the outcome is still pending the customer completing
            // the challenge — never apply it synchronously.
            $paymentRequest->setResponseData(['redirect_html' => $output->redirectHtml]);

            return;
        }

        $paymentRequest->setResponseData(['status' => $output->status]);

        // No 3DS redirect means the outcome is already known synchronously — apply it to the
        // actual Sylius Payment right away instead of waiting on the async webhook, which may
        // be delayed or, in this test environment, never arrive at all. SyliusOrderStateMutator
        // is idempotent (checks the state machine before transitioning), so it's safe to also
        // run again if/when the webhook (NotifyHostedPaymentRequestHandler) eventually shows up.
        $responseBody = \json_decode($output->body, true);
        $execCode = \is_array($responseBody) ? ($responseBody['execCode'] ?? null) : null;
        if (\is_string($execCode)) {
            $this->orderStateMutator->apply(self::idToString($payment->getId()), ExecCodeMapper::toPaymentOutcome($execCode));
        }
    }

    private static function idToString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
