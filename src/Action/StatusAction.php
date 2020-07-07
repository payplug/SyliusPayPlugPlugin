<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;

final class StatusAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /** @var \SM\Factory\FactoryInterface */
    private $stateMachineFactory;

    public function __construct(FactoryInterface $stateMachineFactory)
    {
        $this->stateMachineFactory = $stateMachineFactory;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        $details = $payment->getDetails();

        if (!isset($details['status']) || !isset($details['payment_id'])) {
            $request->markNew();

            return;
        }

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        if (isset($httpRequest->query['status']) && PayPlugApiClientInterface::STATUS_CANCELED === $httpRequest->query['status']) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED;

            $payment->setDetails($details);
        }

        $this->markRequestAs($details['status'], $request);
    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface
        ;
    }

    /**
     * @param mixed $request
     */
    private function markRequestAs(string $status, $request): void
    {
        switch ($status) {
            case PayPlugApiClientInterface::STATUS_CANCELED:
                $request->markCanceled();

                break;
            case PayPlugApiClientInterface::STATUS_CREATED:
                $request->markPending();

                break;
            case PayPlugApiClientInterface::STATUS_CAPTURED:
                $request->markCaptured();

                break;
            case PayPlugApiClientInterface::FAILED:
                $request->markFailed();
                $this->cancelOrder($request);

                break;
            case PayPlugApiClientInterface::REFUNDED:
                $request->markRefunded();

                break;
            default:
                $request->markUnknown();

                break;
        }
    }

    /**
     * @param mixed $request
     */
    private function cancelOrder($request): void
    {
        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        if (!isset($payment->getDetails()['failure']) ||
            $payment->getDetails()['failure']['code'] !== 'timeout') {
            return;
        }

        /** @var \Sylius\Component\Core\Model\OrderInterface $order */
        $order = $payment->getOrder();

        $this->stateMachineFactory
            ->get($order, OrderTransitions::GRAPH)
            ->apply(OrderTransitions::TRANSITION_CANCEL)
        ;
    }
}
