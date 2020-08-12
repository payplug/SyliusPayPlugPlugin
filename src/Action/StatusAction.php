<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use Payum\Core\Request\GetToken;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;

final class StatusAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /** @var \SM\Factory\FactoryInterface */
    private $stateMachineFactory;

    /** @var RefundPaymentHandlerInterface */
    private $refundPaymentHandler;

    public function __construct(
        FactoryInterface $stateMachineFactory,
        RefundPaymentHandlerInterface $refundPaymentHandler
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->refundPaymentHandler = $refundPaymentHandler;
    }

    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        $details = $payment->getDetails();

        if (!isset($details['status'], $details['payment_id'])) {
            $request->markNew();

            return;
        }

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        // notification Url didn't yet call. Let's refresh status
        if (PayPlugApiClientInterface::STATUS_CREATED === $details['status']) {
            $this->gateway->execute($token = new GetToken($httpRequest->query['payum_token']));
            \sleep(1);
            // TODO: check if we can refresh status in a better way than redirect
            throw new HttpRedirect($token->getToken()->getTargetUrl());
        }

        if (isset($httpRequest->query['status']) &&
            PayPlugApiClientInterface::STATUS_CANCELED === $httpRequest->query['status']) {
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

    private function markRequestAs(string $status, GetStatusInterface $request): void
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
            case PayPlugApiClientInterface::STATUS_AUTHORIZED:
                $request->markAuthorized();

                break;
            case PayPlugApiClientInterface::FAILED:
                $request->markFailed();
                $this->cancelOrder($request);

                break;
            case PayPlugApiClientInterface::REFUNDED:
                $this->refundPaymentHandler->updatePaymentStatus($request->getModel());

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
