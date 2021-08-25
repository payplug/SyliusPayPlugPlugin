<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use ArrayAccess;
use Payplug\Exception\BadRequestException;
use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\UnknownApiErrorException;
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
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait, ApiAwareTrait;

    /** @var GenericTokenFactoryInterface|null */
    private $tokenFactory;

    /** @var LoggerInterface */
    private $logger;

    /** @var FlashBagInterface */
    private $flashBag;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(
        LoggerInterface $logger,
        FlashBagInterface $flashBag,
        TranslatorInterface $translator
    ) {
        $this->logger = $logger;
        $this->flashBag = $flashBag;
        $this->translator = $translator;
    }

    public function setGenericTokenFactory(GenericTokenFactoryInterface $genericTokenFactory = null): void
    {
        $this->tokenFactory = $genericTokenFactory;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (isset($details['status']) && PayPlugApiClientInterface::FAILED === $details['status']) {
            // Unset current status to allow to use payplug to change payment method
            unset($details['status']);

            return;
        }

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

        if (isset($details['status']) && $details['status'] === 'pending') {
            // We previously made a payment but not yet "authorized",
            // Unset current status to allow to use payplug to change payment method
            unset($details['status']);
        }

        try {
            $payment = $this->createPayment($details);
            $details['status'] = PayPlugApiClientInterface::STATUS_CREATED;

            throw new HttpRedirect($payment->hosted_payment->payment_url);
        } catch (BadRequestException $badRequestException) {
            $errorObject = $badRequestException->getErrorObject();
            if (null === $errorObject || [] === $errorObject) {
                $this->flashBag->add('error', 'payplug_sylius_payplug_plugin.error.api_unknow_error');

                return;
            }

            $this->notifyErrors($details, $errorObject);

            throw new HttpRedirect($details['hosted_payment']['cancel_url']);
        } catch (UnknownApiErrorException $unknownApiErrorException) {
            $details['status'] = PayPlugApiClientInterface::FAILED;
            $this->flashBag->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.error.api_unknow_error'));

            throw new HttpRedirect($details['hosted_payment']['cancel_url']);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof ArrayAccess
        ;
    }

    private function addNotificationUrl(TokenInterface $token, ArrayObject $details): ArrayObject
    {
        if (null === $this->tokenFactory) {
            throw new RuntimeException();
        }

        $notifyToken = $this->tokenFactory->createNotifyToken($token->getGatewayName(), $token->getDetails());

        $notificationUrl = $notifyToken->getTargetUrl();

        $details['notification_url'] = $notificationUrl;

        return $details;
    }

    private function createPayment(ArrayObject $details): Payment
    {
        try {
            $payment = $this->payPlugApiClient->createPayment($details->getArrayCopy());
            $details['payment_id'] = $payment->id;
            $details['is_live'] = $payment->is_live;

            $this->logger->debug('[PayPlug] Create payment', [
                'payment_id' => $payment->id,
            ]);

            return $payment;
        } catch (BadRequestException $badRequestException) {
            $details['status'] = PayPlugApiClientInterface::FAILED;

            throw $badRequestException;
        } catch (\Throwable $throwable) {
            $details['status'] = PayPlugApiClientInterface::FAILED;

            throw new UnknownApiErrorException(
                'payplug_sylius_payplug_plugin.error.api_unknow_error',
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function notifyErrors(ArrayObject $details, array $errorDetails): void
    {
        if (!isset($errorDetails['details'])) {
            return;
        }

        if (isset($errorDetails['details']['billing']['postcode'])) {
            $this->flashBag->add(
                'error',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.error.billing.postcode', [
                    '%postalCode%' => $details['billing']['postcode'],
                ])
            );
        }

        if (isset($errorDetails['details']['shipping']['postcode'])) {
            $this->flashBag->add(
                'error',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.error.shipping.postcode', [
                    '%postalCode%' => $details['shipping']['postcode'],
                ])
            );
        }

        if (!isset($errorDetails['details']['billing']['postcode']) &&
            !isset($errorDetails['details']['shipping']['postcode'])) {
            $this->flashBag->add('error', 'payplug_sylius_payplug_plugin.error.api_unknow_error');
        }
    }
}
