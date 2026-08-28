<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\Payment;

use DateTimeImmutable;
use LogicException;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Exception\Payment\PaymentNotCompletedException;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Core\Payment\Exception\NotProvidedOrderPaymentException;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

class ApplePayPaymentProvider
{
    public function __construct(
        private PaymentFactoryInterface $paymentFactory,
        private StateMachineInterface $stateMachine,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private PayPlugPaymentDataCreator $paymentDataCreator,
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.apple_pay')]
        private PayPlugApiClientInterface $applePayClient,
        private OrderTokenAssignerInterface $orderTokenAssigner,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Request $request, OrderInterface $order): PaymentInterface
    {
        $paymentMethod = $this->paymentMethodRepository->findOneByGatewayName(ApplePayGatewayFactory::FACTORY_NAME);

        if (!$paymentMethod instanceof PaymentMethodInterface || !$paymentMethod->isEnabled()) {
            throw new LogicException('Apple Pay is not enabled');
        }

        $payment = $this->initApplePaySyliusPaymentState($order);

        Assert::notNull($order->getBillingAddress());
        if (($customer = $order->getBillingAddress()->getCustomer()) instanceof \Sylius\Component\Customer\Model\CustomerInterface) {
            $order->setCustomer($customer);
        }

        Assert::isInstanceOf($order->getChannel(), ChannelInterface::class);

        $paymentDataObject = $this->paymentDataCreator->create(
            $payment,
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

        $paymentData = $paymentDataObject->getArrayCopy();
        $this->logger->notice('[Payplug] ApplePay payment data', ['data' => $paymentData]);

        $paymentResource = $this->applePayClient->createPayment($paymentData);
        $this->logger->notice('[Payplug] ApplePay payment resource', ['payment' => (array) $paymentResource]);

        $details = $paymentData;
        $details['merchant_session'] = $paymentResource->payment_method['merchant_session'];
        $details['status'] = PayPlugApiClientInterface::STATUS_CREATED;
        $details['payment_id'] = $paymentResource->id;
        $details['is_live'] = $paymentResource->is_live;

        $payment->setDetails($details);
        $this->applyRequiredPaymentTransition($payment, PaymentInterface::STATE_NEW);
        $this->applyRequiredOrderPaymentTransition($order, OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->applyRequiredOrderCheckoutTransition($order, OrderCheckoutStates::STATE_COMPLETED);
        $this->orderTokenAssigner->assignTokenValueIfNotSet($order);

        return $payment;
    }

    public function patch(Request $request, OrderInterface $order): PaymentInterface
    {
        $this->logger->notice('[Payplug] ApplePay payment patch', ['order' => $order]);
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (!$lastPayment instanceof PaymentInterface) {
            $this->logger->error('[Payplug] No new payment found for order', ['order' => $order]);

            throw new LogicException();
        }

        $paymentMethod = $lastPayment->getMethod();

        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        Assert::isInstanceOf($paymentMethod->getGatewayConfig(), GatewayConfigInterface::class);

        if (ApplePayGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
            throw new LogicException();
        }

        $paymentResource = $this->applePayClient->retrieve($lastPayment->getDetails()['payment_id']);
        $this->logger->notice('[Payplug] ApplePay payment resource', ['payment' => (array) $paymentResource]);

        try {
            $token = $request->request->all('token');
            if ([] === $token) {
                $token = json_decode($request->getContent(), true)['token'] ?? null; // @phpstan-ignore-line
            }

            if (null === $token) {
                throw new \InvalidArgumentException('Missing token in request');
            }

            $this->logger->notice('[Payplug] ApplePay payment token', ['token' => $token]);
            $data = [
                'apple_pay' => [
                    'payment_token' => $token,
                ],
                'metadata' => (array) $paymentResource->metadata,
            ];

            $this->logger->notice('[Payplug] ApplePay sending update to Payplug', ['data' => $data]);
            /** @var Payment $response */
            $response = $paymentResource->update($data, $this->applePayClient->getConfiguration());
            $this->logger->notice('[Payplug] ApplePay updated response from Payplug', ['response' => (array) $response]);

            if (!$response->is_paid) {
                throw new PaymentNotCompletedException();
            }
            $this->logger->notice('[Payplug] ApplePay payment update response is paid', ['response' => (array) $response]);

            $details = $lastPayment->getDetails();
            $details['status'] = PaymentInterface::STATE_COMPLETED;
            $details['created_at'] = $response->created_at;

            $this->applyRequiredPaymentTransition($lastPayment, PaymentInterface::STATE_COMPLETED);

            if ($this->isResourceIsAuthorized($response)) {
                $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;
            }

            $lastPayment->setDetails($details);

            return $lastPayment;
        } catch (\Exception $exception) {
            $this->logger->error('[Payplug] ApplePay payment update failed', ['exception' => $exception, 'message' => $exception->getMessage()]);
            $this->applyRequiredPaymentTransition($lastPayment, PaymentInterface::STATE_FAILED);

            try {
                $paymentResource->abort($this->applePayClient->getConfiguration());
            } catch (\Throwable $throwable) {
                $this->logger->error('[Payplug] ApplePay payment abort failed', ['payment' => $lastPayment, 'exception' => $throwable]);
            }

            throw new PaymentNotCompletedException();
        }
    }

    public function cancel(OrderInterface $order): void
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        if (!$lastPayment instanceof PaymentInterface) {
            $this->logger->error('[Payplug] No new payment found for order during cancel', ['order' => $order]);

            return;
        }

        $paymentMethod = $lastPayment->getMethod();

        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        Assert::isInstanceOf($paymentMethod->getGatewayConfig(), GatewayConfigInterface::class);

        if (ApplePayGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
            throw new LogicException();
        }

        $this->applyRequiredPaymentTransition($lastPayment, PaymentInterface::STATE_CANCELLED);
    }

    /**
     * @throws NotProvidedOrderPaymentException
     */
    private function initApplePaySyliusPaymentState(OrderInterface $order): PaymentInterface
    {
        Assert::notNull($order->getCurrencyCode());

        $payment = $this->getPayment($order);

        $paymentMethod = $this->paymentMethodRepository->findOneByGatewayName(ApplePayGatewayFactory::FACTORY_NAME);
        $payment->setMethod($paymentMethod);
        $order->addPayment($payment);

        return $payment;
    }

    private function getPayment(OrderInterface $order): PaymentInterface
    {
        $lastPayment = $order->getLastPayment();

        if (
            $lastPayment instanceof PaymentInterface &&
            PaymentInterface::STATE_CART === $lastPayment->getState()
        ) {
            return $lastPayment;
        }

        if (
            $lastPayment instanceof PaymentInterface && OrderInterface::STATE_NEW === $order->getState() &&
            PaymentInterface::STATE_NEW === $lastPayment->getState()
        ) {
            return $lastPayment;
        }

        Assert::string($order->getCurrencyCode());

        /** @phpstan-ignore-next-line */
        return $this->paymentFactory->createWithAmountAndCurrencyCode($order->getTotal(), $order->getCurrencyCode());
    }

    private function applyRequiredPaymentTransition(PaymentInterface $payment, string $targetState): void
    {
        if ($targetState === $payment->getState()) {
            return;
        }

        /** @phpstan-ignore-next-line */
        $targetTransition = $this->stateMachine->getTransitionToState($payment, PaymentTransitions::GRAPH, $targetState);

        if (null !== $targetTransition) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $targetTransition);
        }
    }

    private function applyRequiredOrderPaymentTransition(OrderInterface $order, string $targetState): void
    {
        if ($targetState === $order->getPaymentState()) {
            return;
        }

        $targetTransition = $this->stateMachine->getTransitionToState($order, OrderPaymentTransitions::GRAPH, $targetState);

        if (null !== $targetTransition) {
            $this->stateMachine->apply($order, OrderPaymentTransitions::GRAPH, $targetTransition);
        }
    }

    private function applyRequiredOrderCheckoutTransition(OrderInterface $order, string $targetState): void
    {
        if ($targetState === $order->getPaymentState()) {
            return;
        }

        $targetTransition = $this->stateMachine->getTransitionToState($order, OrderCheckoutTransitions::GRAPH, $targetState);

        if (null !== $targetTransition) {
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $targetTransition);
        }
    }

    private function isResourceIsAuthorized(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney is reviewing the payer’s file
        if (
            $paymentResource->__isset('payment_method') &&
            null !== $paymentResource->__get('payment_method') &&
            \array_key_exists('is_pending', $paymentResource->__get('payment_method')) &&
            true === $paymentResource->__get('payment_method')['is_pending']
        ) {
            return true;
        }

        $now = new DateTimeImmutable();

        return $paymentResource->__isset('authorization') && $paymentResource->__get('authorization') instanceof PaymentAuthorization && null !== $paymentResource->__get('authorization')->__get('expires_at') && $now < $now->setTimestamp($paymentResource->__get('authorization')->__get('expires_at'));
    }
}
