<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
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

        if (PayPlugApiClientInterface::STATUS_CREATED === ($payment->getDetails()['status'] ?? null)) {
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

        $notificationUrl = $this->urlGenerator->generate('sylius_payment_method_notify', ['code' => $payment->getMethod()?->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        $data['notification_url'] = $notificationUrl;

        $paymentRequest->setPayload($data);

        try {
            $payplugPayment = $client->createPayment($data);
        } catch (HttpException $exception) {
            $paymentRequest->setResponseData(\json_decode($exception->getHttpResponse(), true)); // @phpstan-ignore-line
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
}
