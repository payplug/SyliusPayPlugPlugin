<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class StatusAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /** @var RefundPaymentHandlerInterface */
    private $refundPaymentHandler;

    public function __construct(RefundPaymentHandlerInterface $refundPaymentHandler)
    {
        $this->refundPaymentHandler = $refundPaymentHandler;
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

                break;
            case PayPlugApiClientInterface::REFUNDED:
                $this->refundPaymentHandler->updatePaymentStatus($request->getModel());

                break;
            default:
                $request->markUnknown();

                break;
        }
    }
}
