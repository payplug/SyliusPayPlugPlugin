<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

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
    ) {}

    public function __invoke(CapturePaymentRequest $capturePaymentRequest): void
    {
        // Retrieve the current PaymentRequest based on the hash provided in the CapturePaymentRequest command
        $paymentRequest = $this->paymentRequestProvider->provide($capturePaymentRequest);
        $payment = $paymentRequest->getPayment();

        $client = $this->apiClientFactory->createForPaymentMethod($paymentRequest->getPayment()->getMethod());
        $data = $this->paymentDataCreator->create($payment)->getArrayCopy();

        $data['hosted_payment'] = [
            'return_url' => $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL)
        ];

        $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);

        $paymentRequest->setPayload($data);
        $payplugPayment = $client->createPayment($data);
        $arrayPayplugPayment = (array) $payplugPayment;
        $payment->setDetails([
            ...$payment->getDetails(),
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'payment_id' => $payplugPayment->__get('id'),
            ['payplug_response' =>  $arrayPayplugPayment],
        ]);

        $paymentRequest->setResponseData(array_merge($arrayPayplugPayment, [
            'payment_id' => $payplugPayment->__get('id'),
            'redirect_url' => $payplugPayment->hosted_payment->payment_url
        ]));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE
        );
    }
}
