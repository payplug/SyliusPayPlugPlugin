<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use ArrayAccess;
use Payplug\Exception\BadRequestException;
use Payplug\Exception\ForbiddenException;
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
use Webmozart\Assert\Assert;

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
        Assert::isInstanceOf($this->tokenFactory, GenericTokenFactoryInterface::class);
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

            // Pay with a saved card: https://docs.payplug.com/api/guide-savecard-en.html
            if ('PAYER' === $details['initiator']) {
                //if is_paid is true, you can consider the payment as being fully paid,
                if ($payment->is_paid) {
                    //TODO: redirect to thank you page or self
                    return;
                }

                //if both fields authorization and authorized_at are present and filled, the authorization was successful

                //if you got a failure, well you got a failed payment

                //otherwise you’ll have a hosted_payment.payment_url where the payer has to be redirected to complete the payment.

                $details['status'] = PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK;
                $details['hosted_payment'] = [
                    'payment_url' => $payment->hosted_payment->payment_url,
                    'return_url' => $token->getAfterUrl(),
                    'cancel_url' => $token->getTargetUrl() . '?&' . http_build_query(['status' => PayPlugApiClientInterface::STATUS_CANCELED]),
                ];

                $oneClickToken = $this->tokenFactory->createCaptureToken(
                    $token->getGatewayName(),
                    $token->getDetails(),
                    'payplug_sylius_oneclick_verification'
                );

                throw new HttpRedirect($oneClickToken->getAfterUrl());
            }

            throw new HttpRedirect($payment->hosted_payment->payment_url);
        } catch (ForbiddenException $forbiddenException) {
            $accountData = $this->payPlugApiClient->getAccount(true);
            $canSaveCard = (bool) $accountData['permissions']['can_save_cards'];

            /** @var \Sylius\Component\Core\Model\PaymentMethod $paymentMethod */
            $paymentMethod = $request->getFirstModel()->getMethod();
            /** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();
            $config = $gatewayConfig->getConfig();
            $config['oneClick'] = $canSaveCard;
            $gatewayConfig->setConfig($config);
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
