<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use PayPlug\SyliusPayPlugPlugin\StateMachine\Transition\OrderPaymentTransitions;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class StatusAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /** @var RefundPaymentHandlerInterface */
    private $refundPaymentHandler;

    /** @var \PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler */
    private $paymentNotificationHandler;
    private RequestStack $requestStack;

    public function __construct(
        RefundPaymentHandlerInterface $refundPaymentHandler,
        PaymentNotificationHandler $paymentNotificationHandler,
        RequestStack $requestStack
    ) {
        $this->refundPaymentHandler = $refundPaymentHandler;
        $this->paymentNotificationHandler = $paymentNotificationHandler;
        $this->requestStack = $requestStack;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        $details = new ArrayObject($payment->getDetails());

        if (!isset($details['status'], $details['payment_id'])) {
            $request->markNew();

            return;
        }

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        //If we don't have received the notification when we reach this page, call the API manually to update the status
        if (PayPlugApiClientInterface::STATUS_CREATED === $details['status']
            && isset($httpRequest->query['payum_token'])) {
            $resource = $this->payPlugApiClient->retrieve($details['payment_id']);
            $this->paymentNotificationHandler->treat($request->getFirstModel(), $resource, $details);
        }

        if (isset($httpRequest->query['status']) &&
            PayPlugApiClientInterface::STATUS_CANCELED === $httpRequest->query['status']) {
            // we need to show a specific error message when the payment is cancelled using the 1click feature
            if (PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK === $details['status']) {
                $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.transaction_failed_1click');
            }

            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED;
        }

        if (PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK === $details['status']) {
            $resource = $this->payPlugApiClient->retrieve($details['payment_id']);
            $this->paymentNotificationHandler->treat($request->getFirstModel(), $resource, $details);
        }

        if (PaymentInterface::STATE_PROCESSING === $details['status']) {
            $resource = $this->payPlugApiClient->retrieve($details['payment_id']);
            $this->paymentNotificationHandler->treat($request->getFirstModel(), $resource, $details);
        }

        $payment->setDetails($details->getArrayCopy());
        $this->markRequestAs($details['status'], $request);
    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface;
    }

    private function markRequestAs(string $status, GetStatusInterface $request): void
    {
        switch ($status) {
            case PayPlugApiClientInterface::STATUS_CANCELED:
                $request->markCanceled();

                break;
            case PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY:
                $request->markCanceled();
                $this->markOrderPaymentAsAwaitingPayment($request);

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
    private function markOrderPaymentAsAwaitingPayment($request): void
    {
        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $stateMachine = $this->stateMachineFactory->get($order, OrderPaymentTransitions::GRAPH);

        if (!$stateMachine->can(OrderPaymentTransitions::TRANSITION_REQUEST_PAYMENT)) {
            return;
        }

        $this->stateMachineFactory
            ->get($order, OrderPaymentTransitions::GRAPH)
            ->apply(OrderPaymentTransitions::TRANSITION_REQUEST_PAYMENT);
    }
}
