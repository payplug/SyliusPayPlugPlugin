<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Sylius\Component\Core\Model\PaymentInterface;

final class StatusAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

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

        switch ($details['status']) {
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
                $request->markRefunded();
                break;
            default:
                $request->markUnknown();
                break;
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface
        ;
    }
}
