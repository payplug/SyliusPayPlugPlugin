<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefusalClassifier;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final class CapturePaymentRequestHandler
{
    private const PPRO_NOT_AVAILABLE_MESSAGE = 'payplug_sylius_payplug_plugin.error.ppro_not_available';

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PayPlugPaymentDataCreator $paymentDataCreator,
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')]
        private UrlProviderInterface $afterPayUrlProvider,
        private LoggerInterface $logger,
        private PproConfigurationRefresherInterface $pproConfigurationRefresher,
        private PproRefusalClassifier $pproRefusalClassifier,
        #[Autowire(service: 'sylius_shop.provider.order_pay.final_url')]
        private FinalUrlProviderInterface $finalUrlProvider,
        private RequestStack $requestStack,
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
            $this->failPaymentRequest($paymentRequest, $payment, $method, $exception);

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
     * A PPRO payment refused for its billing country or its amount means the stored PayPlug limits
     * let the method be shown where PayPlug does not accept it: they are refreshed, and the customer
     * is sent back to the order page to pick another payment method. No PayPlug payment exists after
     * a refusal, so there is nothing to cancel.
     */
    private function failPaymentRequest(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PaymentMethodInterface $method,
        HttpException $exception,
    ): void {
        $responseBody = $exception->getHttpResponse();
        $decoded = \json_decode($responseBody, true);
        /** @var array<string, mixed> $responseData */
        $responseData = \is_array($decoded) ? $decoded : [];
        $factoryName = $method->getGatewayConfig()?->getFactoryName();

        $refusal = PproPaymentMethods::isPpro($factoryName) ? $this->pproRefusalClassifier->classify($responseBody) : null;

        if (null === $refusal) {
            $this->logger->error('[PayPlug] Payment creation failed', ['factory_name' => $factoryName, 'response' => $responseBody]);
        } else {
            $this->logger->info('[PayPlug] PPRO payment refused for its billing country or amount; limits refreshed.', [
                'factory_name' => $factoryName,
                'refusal' => $refusal,
            ]);
            // Flushed by the doctrine_transaction middleware of the payment request command bus.
            $this->pproConfigurationRefresher->refresh($method, false);
            $responseData['redirect_url'] = $this->finalUrlProvider->getUrl($payment, UrlGeneratorInterface::ABSOLUTE_URL);
            $responseData['error'] = self::PPRO_NOT_AVAILABLE_MESSAGE;
            $this->addErrorFlash();
        }

        $paymentRequest->setResponseData($responseData);
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }

    private function addErrorFlash(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', self::PPRO_NOT_AVAILABLE_MESSAGE);
        }
    }
}
