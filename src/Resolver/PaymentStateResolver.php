<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentStateResolver implements PaymentStateResolverInterface
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactory $payPlugApiClientFactory,
        private EntityManagerInterface $paymentEntityManager,
    ) {
    }

    public function resolve(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        if (
            !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
            PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()
        ) {
            return;
        }

        $details = $payment->getDetails();
        if (!isset($details['payment_id'])) {
            return;
        }

        $payplugApiClient = $this->payPlugApiClientFactory->createForPaymentMethod($paymentMethod);
        $payment = $payplugApiClient->retrieve((string) $details['payment_id']);

        switch (true) {
            case $payment->is_paid:
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);

                break;
            case null !== $payment->failure:
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_FAIL);

                break;
            case $this->isAuthorized($payment):
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_AUTHORIZE);

                break;
            default:
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_PROCESS);
        }

        $this->paymentEntityManager->flush();
    }

    private function applyTransition(Payment $payment, string $transition): void
    {
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        }
    }

    private function isAuthorized(Payment $payment): bool
    {
        $now = new \DateTimeImmutable();

        return $payment->__isset('authorization') && $payment->__get('authorization') instanceof PaymentAuthorization && null !== $payment->__get('authorization')->__get('expires_at') && $now < $now->setTimestamp($payment->__get('authorization')->__get('expires_at'));
    }
}
