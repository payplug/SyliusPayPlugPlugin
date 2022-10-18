<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\Payment;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Exception\Payment\PaymentNotCompletedException;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\PaymentTokenProvider;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Payum\Core\Payum;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use SM\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Payment\Exception\NotProvidedOrderPaymentException;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

class ApplePayPaymentProvider
{
    private PaymentFactoryInterface $paymentFactory;
    private StateMachineFactoryInterface $stateMachineFactory;
    private PaymentMethodRepositoryInterface $paymentMethodRepository;
    private PaymentTokenProvider $paymentTokenProvider;
    private Payum $payum;
    private PayPlugPaymentDataCreator $paymentDataCreator;
    private PayPlugApiClientInterface $applePayClient;
    private EntityManagerInterface $entityManager;
    private OrderTokenAssignerInterface $orderTokenAssigner;

    public function __construct(
        PaymentFactoryInterface $paymentFactory,
        StateMachineFactoryInterface $stateMachineFactory,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        PaymentTokenProvider $paymentTokenProvider,
        Payum $payum,
        PayPlugPaymentDataCreator $paymentDataCreator,
        PayPlugApiClientInterface $applePayClient,
        EntityManagerInterface $entityManager,
        OrderTokenAssignerInterface $orderTokenAssigner
    ) {
        $this->paymentFactory = $paymentFactory;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentTokenProvider = $paymentTokenProvider;
        $this->payum = $payum;
        $this->paymentDataCreator = $paymentDataCreator;
        $this->applePayClient = $applePayClient;
        $this->entityManager = $entityManager;
        $this->orderTokenAssigner = $orderTokenAssigner;
    }

    public function provide(Request $request, OrderInterface $order): PaymentInterface
    {
        $paymentMethod = $this->paymentMethodRepository->findOneByGatewayName(ApplePayGatewayFactory::FACTORY_NAME);

        if (!$paymentMethod instanceof PaymentMethodInterface || !$paymentMethod->isEnabled()) {
            throw new LogicException('Apple Pay is not enabled');
        }

        $payment = $this->initApplePaySyliusPaymentState($order, PaymentInterface::STATE_NEW);

        Assert::notNull($order->getShippingAddress());
        if (null !== $customer = $order->getShippingAddress()->getCustomer()) {
            $order->setCustomer($customer);
        }

        Assert::isInstanceOf($order->getChannel(), ChannelInterface::class);

        $paymentData = $this->paymentDataCreator->create(
            $payment,
            ApplePayGatewayFactory::FACTORY_NAME,
            [
                'apple_pay' => [
                    'domain_name' => $order->getChannel()->getHostname(),
                    /* @phpstan-ignore-next-line */
                    'application_data' => \base64_encode(\json_encode([
                        'apple_pay_domain' => $order->getChannel()->getHostname(),
                    ])),
                ],
            ],
        );

        $token = $this->paymentTokenProvider->getPaymentToken($payment);
        $notificationUrl = $this->payum->getTokenFactory()->createNotifyToken($token->getGatewayName(), $token->getDetails());

        $details = (array) $paymentData;
        $details['hosted_payment'] = [
            'notification_url' => $notificationUrl->getTargetUrl(),
        ];

        $paymentResource = $this->applePayClient->createPayment((array) $paymentData);

        $details['merchant_session'] = $paymentResource->payment_method['merchant_session'];
        $details['status'] = PayPlugApiClientInterface::STATUS_CREATED;
        $details['payment_id'] = $paymentResource->id;
        $details['is_live'] = $paymentResource->is_live;

        $payment->setDetails($details);
        $this->applyRequiredTransition($payment, PaymentInterface::STATE_NEW);
        $this->entityManager->flush();

        return $payment;
    }

    /**
     * @throws NotProvidedOrderPaymentException
     */
    private function initApplePaySyliusPaymentState(OrderInterface $order, string $targetState): PaymentInterface
    {
        $order->getPayments()->clear();

        Assert::notNull($order->getCurrencyCode());
        /** @var PaymentInterface $payment */
        $payment = $this->paymentFactory->createWithAmountAndCurrencyCode($order->getTotal(), $order->getCurrencyCode());
        $paymentMethod = $this->paymentMethodRepository->findOneByGatewayName(ApplePayGatewayFactory::FACTORY_NAME);

        $lastPayment = $this->getLastPayment($order);

        if (null !== $lastPayment) {
            $paymentMethod = $lastPayment->getMethod();
        }

        if (null === $paymentMethod) {
            throw new NotProvidedOrderPaymentException();
        }

        $payment->setMethod($paymentMethod);
        $this->applyRequiredTransition($payment, $targetState);
        $order->addPayment($payment);

        return $payment;
    }

    public function applyRequiredTransition(PaymentInterface $payment, string $targetState): void
    {
        if ($targetState === $payment->getState()) {
            return;
        }

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);

        /** @phpstan-ignore-next-line */
        $targetTransition = $stateMachine->getTransitionToState($targetState);

        if (null !== $targetTransition) {
            $stateMachine->apply($targetTransition);
        }
    }

    private function getLastPayment(OrderInterface $order): ?PaymentInterface
    {
        $lastCancelledPayment = $order->getLastPayment(PaymentInterface::STATE_CANCELLED);

        if (null !== $lastCancelledPayment) {
            return $lastCancelledPayment;
        }

        return $order->getLastPayment(PaymentInterface::STATE_FAILED);
    }

    public function patch(Request $request, OrderInterface $order): PaymentInterface
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (!$lastPayment instanceof PaymentInterface) {
            throw new LogicException();
        }

        $paymentMethod = $lastPayment->getMethod();

        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        Assert::isInstanceOf($paymentMethod->getGatewayConfig(), GatewayConfigInterface::class);

        if (ApplePayGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
            throw new LogicException();
        }

        $paymentResource = $this->applePayClient->retrieve($lastPayment->getDetails()['payment_id']);

        try {
            $applePay = [];
            $applePay['payment_token'] = $request->get('token');

            $data = [
                ApplePayGatewayFactory::PAYMENT_METHOD_APPLE_PAY => $applePay,
            ];

            /** @var Payment $response */
            $response = $paymentResource->update($data);
            $details = $lastPayment->getDetails();

            if (!$response->is_paid) {
                $this->applyRequiredTransition($lastPayment, PaymentInterface::STATE_FAILED);

                throw new PaymentNotCompletedException();
            }

            $details['status'] = PaymentInterface::STATE_COMPLETED;
            $details['created_at'] = $response->created_at;

            $order = $lastPayment->getOrder();
            Assert::isInstanceOf($order, OrderInterface::class);

            $this->orderTokenAssigner->assignTokenValueIfNotSet($order);

            $this->entityManager->flush();

            $this->applyRequiredTransition($lastPayment, PaymentInterface::STATE_COMPLETED);

            if ($this->isResourceIsAuthorized($response)) {
                $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;
            }

            $lastPayment->setDetails($details);

            $this->entityManager->flush();

            return $lastPayment;
        } catch (\Exception $exception) {
            $paymentResource->abort();
            $this->applyRequiredTransition($lastPayment, PaymentInterface::STATE_FAILED);

            throw new PaymentNotCompletedException();
        }
    }

    private function isResourceIsAuthorized(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney is reviewing the payer’s file
        if ($paymentResource->__isset('payment_method') &&
            null !== $paymentResource->__get('payment_method') &&
            \array_key_exists('is_pending', $paymentResource->__get('payment_method')) &&
            true === $paymentResource->__get('payment_method')['is_pending']) {
            return true;
        }

        $now = new DateTimeImmutable();
        if ($paymentResource->__isset('authorization') &&
            $paymentResource->__get('authorization') instanceof PaymentAuthorization &&
            null !== $paymentResource->__get('authorization')->__get('expires_at') &&
            $now < $now->setTimestamp($paymentResource->__get('authorization')->__get('expires_at'))) {
            return true;
        }

        return false;
    }

    public function cancel(OrderInterface $order): void
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (!$lastPayment instanceof PaymentInterface) {
            throw new LogicException();
        }

        $paymentMethod = $lastPayment->getMethod();

        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        Assert::isInstanceOf($paymentMethod->getGatewayConfig(), GatewayConfigInterface::class);

        if (ApplePayGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
            throw new LogicException();
        }

        $this->applyRequiredTransition($lastPayment, PaymentInterface::STATE_CANCELLED);
        $this->entityManager->flush();
    }
}
