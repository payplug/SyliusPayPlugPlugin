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
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
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
        // Only used by the 3DS-redirect branch below, currently commented out — see the note
        // there (blocked on unified-plugin-core exposing $successUrl/$cancelUrl).
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
        /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        $details = $payment->getDetails();
        $hfToken = $details['hosted_fields_token'] ?? null;
        if (!\is_string($hfToken) || '' === $hfToken) {
            throw new \LogicException('No hosted fields token found on the payment.');
        }

        $amount = $payment->getAmount();
        $currencyCode = $payment->getCurrencyCode();
        if (null === $amount || null === $currencyCode) {
            throw new \LogicException('Payment amount or currency is not set.');
        }

        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $accountId = $gatewayConfig[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null;
        $submerchantExternalId = $gatewayConfig[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null;
        if (!\is_string($accountId) || '' === $accountId || !\is_string($submerchantExternalId) || '' === $submerchantExternalId) {
            throw new \LogicException('Hosted Fields account id or submerchant id is not configured for this payment method.');
        }

        try {
            $order = $payment->getOrder();
            $orderId = $order?->getNumber() ?? self::idToString($payment->getId());
            $firstItemOrFalse = $order?->getItems()->first();
            $firstItem = false !== $firstItemOrFalse ? $firstItemOrFalse : null;

            $common = new CommonFieldsDto($accountId, $amount, \strtoupper($currencyCode), $orderId, $submerchantExternalId);
            $common->description = null !== $firstItem ? $firstItem->getProductName() : null;
            $common->notificationUrl = $this->urlGenerator->generate(
                'sylius_payment_request_notify',
                ['hash' => (string) $paymentRequest->getHash()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            // Blocked on unified-plugin-core exposing $successUrl/$cancelUrl on CommonFieldsDto (see
            // this plan's "Blocking external prerequisite" section) — uncomment once that lands and
            // the composer dependency is bumped:
            // $successUrl = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
            // $common->successUrl = $successUrl;
            // $common->cancelUrl = $successUrl . '?' . http_build_query(['status' => 'canceled']);

            $selectedBrand = $details['hosted_fields_selected_brand'] ?? null;
            $paymentMethodDetails = \is_string($selectedBrand) && '' !== $selectedBrand
                ? ['details' => ['selectedBrand' => $selectedBrand]]
                : null;

            $customer = $order?->getCustomer();
            if (null === $customer || null === $customer->getEmail()) {
                throw new \LogicException('Customer email is not set for the payment.');
            }
            $customerDto = new CustomerDto(self::idToString($customer->getId()), $customer->getEmail());

            $request = $this->requestStack->getCurrentRequest();
            $browserDto = null !== $request
                ? new BrowserDto(
                    $request->getClientIp() ?? '',
                    $request->headers->get('referer', '') ?? '',
                    $request->headers->get('User-Agent', '') ?? '',
                )
                : null;

            $dto = new HostedFieldDto($common, $hfToken, $browserDto, $customerDto, $paymentMethodDetails);

            $this->logger->debug('[PayPlug debug] Unified API hosted payment request payload.', [
                'payload' => $dto->createPayloadBody(),
            ]);

            $output = $this->hostedPaymentCreator->createHostedPayment($dto);
        } catch (ApiException | InvalidHostedFieldException | \LogicException $e) {
            $this->logger->error('[PayPlug][UPC] Hosted payment creation failed.', [
                'sylius_payment_id' => $payment->getId(),
                'error' => $e->getMessage(),
            ]);
            $paymentRequest->setResponseData(['error' => $e->getMessage()]);
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        $payment->setDetails([
            ...$details,
            'hosted_fields_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        if (null !== $output->redirectUrl) {
            $paymentRequest->setResponseData(['redirect_url' => $output->redirectUrl]);
        } else {
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

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private static function idToString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
