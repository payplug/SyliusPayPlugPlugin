<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use SM\Factory\FactoryInterface;
use SM\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentStateResolver implements PaymentStateResolverInterface
{
    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var PayPlugApiClientInterface */
    private $payPlugApiClient;

    /** @var EntityManagerInterface */
    private $paymentEntityManager;

    public function __construct(
        FactoryInterface $stateMachineFactory,
        PayPlugApiClientInterface $payPlugApiClient,
        EntityManagerInterface $paymentEntityManager
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->payPlugApiClient = $payPlugApiClient;
        $this->paymentEntityManager = $paymentEntityManager;
    }

    public function resolve(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        if (!$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
            PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
            return;
        }

        $details = $payment->getDetails();

        if (!isset($details['payment_id'])) {
            return;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig()->getConfig();

        $this->payPlugApiClient->initialise($gatewayConfig['secretKey']);

        $paymentStateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);

        $payment = $this->payPlugApiClient->retrieve((string) $details['payment_id']);

        switch (true) {
            case $payment->is_paid:
                $this->applyTransition($paymentStateMachine, PaymentTransitions::TRANSITION_COMPLETE);

                break;
            case null !== $payment->failure:
                $this->applyTransition($paymentStateMachine, PaymentTransitions::TRANSITION_FAIL);

                break;
            case $this->isAuthorized($payment):
                $this->applyTransition($paymentStateMachine, PaymentTransitions::TRANSITION_AUTHORIZE);

                break;
            default:
                $this->applyTransition($paymentStateMachine, PaymentTransitions::TRANSITION_PROCESS);
        }

        $this->paymentEntityManager->flush();
    }

    private function applyTransition(StateMachineInterface $paymentStateMachine, string $transition): void
    {
        if ($paymentStateMachine->can($transition)) {
            $paymentStateMachine->apply($transition);
        }
    }

    private function isAuthorized(Payment $payment): bool
    {
        $now = new \DateTimeImmutable();
        if ($payment->__isset('authorization') &&
            $payment->__get('authorization') instanceof PaymentAuthorization &&
            null !== $payment->__get('authorization')->__get('expires_at') &&
            $now < $now->setTimestamp($payment->__get('authorization')->__get('expires_at'))) {
            return true;
        }

        return false;
    }
}
