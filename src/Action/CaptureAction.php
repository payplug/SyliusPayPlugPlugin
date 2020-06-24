<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\RuntimeException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use Psr\Log\LoggerInterface;

final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait, ApiAwareTrait;

    /** @var GenericTokenFactoryInterface|null */
    private $tokenFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setGenericTokenFactory(GenericTokenFactoryInterface $genericTokenFactory = null): void
    {
        $this->tokenFactory = $genericTokenFactory;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (isset($details['status'], $details['payment_id'])) {
            if (PayPlugApiClientInterface::STATUS_CREATED !== $details['status']) {
                return;
            }

            $times = 0;

            do {
                $payment = $this->payPlugApiClient->retrieve((string) $details['payment_id']);

                if ($payment->is_paid) {
                    $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;

                    return;
                }

                sleep(1);

                ++$times;
            } while ($times < 3);

            return;
        }

        /** @var TokenInterface $token */
        $token = $request->getToken();

        $details = $this->addNotificationUrl($token, $details);

        $details['hosted_payment'] = [
            'return_url' => $token->getAfterUrl(),
            'cancel_url' => $token->getTargetUrl() . '?&' . http_build_query(['status' => PayPlugApiClientInterface::STATUS_CANCELED]),
        ];

        $payment = $this->createPayment($details);

        $details['status'] = PayPlugApiClientInterface::STATUS_CREATED;

        throw new HttpRedirect($payment->hosted_payment->payment_url);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }

    private function addNotificationUrl(TokenInterface $token, ArrayObject $details): \Payum\Core\Bridge\Spl\ArrayObject
    {
        if (null === $this->tokenFactory) {
            throw new RuntimeException();
        }

        $notifyToken = $this->tokenFactory->createNotifyToken($token->getGatewayName(), $token->getDetails());

        $notificationUrl = $notifyToken->getTargetUrl();

        $details['notification_url'] = $notificationUrl;

        return $details;
    }

    private function createPayment(ArrayObject $details): \Payplug\Resource\Payment
    {
        $payment = $this->payPlugApiClient->createPayment($details->getArrayCopy());
        $details['payment_id'] = $payment->id;
        $details['is_live'] = $payment->is_live;

        $this->logger->debug('[PayPlug] Create payment', [
            'payment_id' => $payment->id,
        ]);

        return $payment;
    }
}
