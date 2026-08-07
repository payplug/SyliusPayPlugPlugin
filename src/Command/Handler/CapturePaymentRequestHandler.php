<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\UnifiedApiHostedPaymentServiceFactory;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\Payment\HfTokenProvider;
use PayplugUnifiedCore\Exceptions\ApiException;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final class CapturePaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PayPlugPaymentDataCreator $paymentDataCreator,
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')]
        private UrlProviderInterface $afterPayUrlProvider,
        private LoggerInterface $logger,
        private UnifiedApiHostedPaymentServiceFactory $hostedPaymentServiceFactory,
        private HfTokenProvider $hfTokenProvider,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(CapturePaymentRequest $capturePaymentRequest): void
    {
        // Retrieve the current PaymentRequest based on the hash provided in the CapturePaymentRequest command
        $paymentRequest = $this->paymentRequestProvider->provide($capturePaymentRequest);
        /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        if (UhfGatewayFactory::FACTORY_NAME === $method->getGatewayConfig()?->getFactoryName()) {
            $this->captureViaUnifiedApi($paymentRequest, $payment, $method);

            return;
        }

        if (
            PayPlugApiClientInterface::STATUS_CREATED === ($payment->getDetails()['status'] ?? null) &&
            ($payment->getDetails()['factory_name'] ?? null) === $method->getGatewayConfig()?->getFactoryName()
        ) {
            $paymentRequest->setResponseData([
                'retry' => true,
                'message' => 'Payment already created',
                'payment_id' => $payment->getDetails()['payment_id'] ?? 'unknown',
                'redirect_url' => $payment->getDetails()['redirect_url'] ?? null, // @phpstan-ignore-line
            ]);

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );

            return;
        }

        $client = $this->apiClientFactory->createForPaymentMethod($method);
        $data = $this->paymentDataCreator->create($payment)->getArrayCopy();

        $returnUrl = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $data['hosted_payment'] = [
            'return_url' => $returnUrl,
            'cancel_url' => $returnUrl . '?&' . http_build_query(['status' => PayPlugApiClientInterface::STATUS_CANCELED]),
        ];

        $paymentRequest->setPayload($data);

        try {
            $payplugPayment = $client->createPayment($data);
        } catch (HttpException $exception) {
            $paymentRequest->setResponseData(\json_decode($exception->getHttpResponse(), true)); // @phpstan-ignore-line
            $this->logger->error('[PayPlug] Scalapay capture failed', ['response' => $exception->getHttpResponse()]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );

            return;
        }
        $arrayPayplugPayment = (array) $payplugPayment;
        $payment->setDetails([
            ...$payment->getDetails(),
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'factory_name' => $method->getGatewayConfig()?->getFactoryName(),
            'payment_id' => $payplugPayment->__get('id'),
            'payplug_response' => $arrayPayplugPayment,
            'redirect_url' => $payplugPayment->hosted_payment->payment_url, // @phpstan-ignore-line
        ]);

        $paymentRequest->setResponseData(array_merge($arrayPayplugPayment, [
            'payment_id' => $payplugPayment->__get('id'),
            'redirect_url' => $payplugPayment->hosted_payment->payment_url, // @phpstan-ignore-line
        ]));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    /**
     * Creates/confirms the payment through UPC's Unified API from the shopper's hfToken, instead of
     * the legacy PayPlug SDK path above. Never applies an order/payment state transition beyond the
     * PaymentRequest's own workflow (COMPLETE/FAIL) — the Unified API's asynchronous webhook is the
     * single source of truth for the final PaymentOutcome, handled by a later task's
     * NotifyPaymentRequestHandler branch, whether or not a 3DS redirect happened here.
     */
    private function captureViaUnifiedApi(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PaymentMethodInterface $method,
    ): void {
        if (
            PayPlugApiClientInterface::STATUS_CREATED === ($payment->getDetails()['status'] ?? null) &&
            UhfGatewayFactory::FACTORY_NAME === ($payment->getDetails()['factory_name'] ?? null)
        ) {
            $paymentRequest->setResponseData([
                'retry' => true,
                'message' => 'Payment already created',
                'payment_id' => $payment->getDetails()['payment_id'] ?? 'unknown',
                'redirect_url' => $payment->getDetails()['redirect_url'] ?? null,
            ]);

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );

            return;
        }

        $hfToken = $this->hfTokenProvider->getHfToken($payment);

        if (null === $hfToken) {
            $this->logger->error('[PayPlug] UHF capture failed: no hfToken available for this payment.', [
                'payment_id' => $payment->getId(),
            ]);
            $paymentRequest->setResponseData(['error' => 'missing_hf_token']);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );

            return;
        }

        $order = $payment->getOrder();
        $notificationUrl = $this->urlGenerator->generate(
            'sylius_payment_method_notify',
            ['code' => $method->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $result = $this->hostedPaymentServiceFactory->createForPaymentMethod($method)->createHostedPayment(
                $hfToken,
                $payment->getAmount() ?? 0,
                $payment->getCurrencyCode() ?? '',
                (string) $order?->getId(), // @phpstan-ignore-line
                null,
                null,
                null,
                null,
                null,
                $notificationUrl,
            );
        } catch (ApiException $exception) {
            $this->logger->error('[PayPlug] UHF capture failed', ['error' => $exception->getMessage()]);
            $paymentRequest->setResponseData(['error' => $exception->getMessage()]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );

            return;
        }

        $responseBody = \json_decode($result->body, true);
        $operationId = \is_array($responseBody) && \is_string($responseBody['id'] ?? null) ? $responseBody['id'] : null;

        $payment->setDetails([
            ...$payment->getDetails(),
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'factory_name' => UhfGatewayFactory::FACTORY_NAME,
            'payment_id' => $operationId,
            'redirect_url' => $result->redirectUrl,
        ]);

        $paymentRequest->setResponseData([
            'payment_id' => $operationId,
            'redirect_url' => $result->redirectUrl,
            'status' => $result->status,
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
