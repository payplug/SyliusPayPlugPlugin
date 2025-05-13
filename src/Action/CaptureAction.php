<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use ArrayAccess;
use Payplug\Exception\BadRequestException;
use Payplug\Exception\ForbiddenException;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Exception\UnknownApiErrorException;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AbortPaymentProcessor;
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
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => PayPlugGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => OneyGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => BancontactGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => AmericanExpressGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[Autoconfigure(public: true)]
final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /** @var GenericTokenFactoryInterface|null */
    private $tokenFactory;

    public function __construct(
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private AbortPaymentProcessor $abortPaymentProcessor,
        private RequestStack $requestStack,
        private RepositoryInterface $payplugCardRepository,
    ) {
    }

    public function setGenericTokenFactory(GenericTokenFactoryInterface $genericTokenFactory = null): void
    {
        $this->tokenFactory = $genericTokenFactory;
    }

    public function execute($request): void
    {
        Assert::isInstanceOf($this->tokenFactory, GenericTokenFactoryInterface::class);
        RequestNotSupportedException::assertSupports($this, $request);

        $paymentModel = $request->getFirstModel();
        Assert::isInstanceOf($paymentModel, PaymentInterface::class);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (
            isset($details['payment_method']) &&
            ApplePayGatewayFactory::PAYMENT_METHOD_APPLE_PAY === $details['payment_method']
        ) {
            $this->abortPaymentProcessor->process($paymentModel);
            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED;

            return;
        }

        if (
            PayPlugApiClientInterface::FAILED === ($details['status'] ?? null) &&
            PayPlugApiClientInterface::INTEGRATED_PAYMENT_INTEGRATION === ($details['integration'] ?? null)
        ) {
            // Do not try to capture a failed integrated payment and do not remove status
            return;
        }

        if (isset($details['status']) && PayPlugApiClientInterface::FAILED === $details['status']) {
            // Unset current status to allow to use payplug to change payment method
            unset($details['status']);

            return;
        }

        if (
            isset($details['status'], $details['payment_id']) &&
            PayPlugApiClientInterface::STATUS_CREATED !== $details['status']
        ) {
            return;
        }

        if (
            array_key_exists('status', $paymentModel->getDetails()) &&
            PayPlugApiClientInterface::STATUS_CAPTURED === $paymentModel->getDetails()['status']
        ) {
            return;
        }

        /** @var TokenInterface $token */
        $token = $request->getToken();

        $details = $this->addNotificationUrl($token, $details);

        $details['hosted_payment'] = [
            'return_url' => $token->getAfterUrl(),
            'cancel_url' => $token->getTargetUrl() . '?&' . http_build_query(['status' => PayPlugApiClientInterface::STATUS_CANCELED]),
        ];

        if (isset($details['status']) && 'pending' === $details['status']) {
            // We previously made a payment but not yet "authorized",
            // Unset current status to allow to use payplug to change payment method
            unset($details['status']);
        }

        if (
            !in_array(
                $details['payment_method'],
                array_merge(
                    [ApplePayGatewayFactory::PAYMENT_METHOD_APPLE_PAY],
                    OneyGatewayFactory::PAYMENT_CHOICES,
                ),
                true,
            )
        ) {
            // clean other detail values
            if ($details->offsetExists('payment_context')) {
                unset($details['payment_context']);
            }

            if ($details->offsetExists('merchant_session')) {
                unset($details['merchant_session']);
            }
        }

        try {
            // gateway payplug case: open many browsers and pay by save card in any of these browsers
            $cardId = $this->requestStack->getSession()->get('payplug_payment_method');
            if (
                null !== $cardId &&
                'PAYER' !== $details['initiator'] &&
                $paymentModel->getMethod() instanceof PaymentMethodInterface &&
                PayPlugGatewayFactory::FACTORY_NAME === $token->getGatewayName()
            ) {
                $card = $this->payplugCardRepository->find($cardId);
                if ($card instanceof Card) {
                    $details['payment_method'] = $card->getExternalId();
                    $details['initiator'] = 'PAYER';
                }
            }

            $payment = $this->createPayment($details, $paymentModel);
            $details['status'] = PayPlugApiClientInterface::STATUS_CREATED;

            // Pay with a saved card: https://docs.payplug.com/api/guide-savecard-en.html
            if ('PAYER' === $details['initiator']) {
                //if is_paid is true, you can consider the payment as being fully paid,
                if ($payment->is_paid) {
                    return;
                }

                $now = new \DateTimeImmutable();
                if (
                    $payment->__isset('authorization') &&
                    $payment->__get('authorization') instanceof PaymentAuthorization &&
                    null !== $payment->__get('authorization')->__get('expires_at') &&
                    $now < $now->setTimestamp($payment->__get('authorization')->__get('expires_at'))
                ) {
                    return;
                }

                $details['status'] = PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK;
                $details['hosted_payment'] = [
                    'payment_url' => $payment->hosted_payment->payment_url,
                    'return_url' => $token->getAfterUrl(),
                    'cancel_url' => $token->getTargetUrl() . '?&' . http_build_query(['status' => PayPlugApiClientInterface::STATUS_CANCELED]),
                ];

                $oneClickToken = $this->tokenFactory->createCaptureToken(
                    $token->getGatewayName(),
                    $token->getDetails(),
                    'payplug_sylius_oneclick_verification',
                );

                throw new HttpRedirect($oneClickToken->getAfterUrl());
            }

            throw new HttpRedirect($payment->hosted_payment->payment_url);
        } catch (ForbiddenException) {
            $accountData = $this->payPlugApiClient->getAccount(true);
            $canSaveCard = (bool) $accountData['permissions']['can_save_cards'];

            $paymentMethod = $paymentModel->getMethod();
            if (
                $paymentMethod instanceof PaymentMethodInterface &&
                ($gatewayConfig = $paymentMethod->getGatewayConfig()) instanceof GatewayConfigInterface
            ) {
                $config = $gatewayConfig->getConfig();
                $config[PayPlugGatewayFactory::ONE_CLICK] = $canSaveCard;
                $gatewayConfig->setConfig($config);
            }
        } catch (BadRequestException $badRequestException) {
            $errorObject = $badRequestException->getErrorObject();
            if (null === $errorObject || [] === $errorObject) {
                $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.api_unknow_error');

                return;
            }

            $this->notifyErrors($details, $errorObject);

            throw new HttpRedirect($details['hosted_payment']['cancel_url']);
        } catch (UnknownApiErrorException) {
            $details['status'] = PayPlugApiClientInterface::FAILED;
            $this->displayGenericError($details);

            throw new HttpRedirect($details['hosted_payment']['cancel_url']);
        }
    }

    private function displayGenericError(ArrayObject $details): void
    {
        if ('PAYER' === $details['initiator']) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.error.transaction_failed_1click'));

            return;
        }

        $this->requestStack->getSession()->getFlashBag()->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.error.api_unknow_error'));
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

    /**
     * @throws UnknownApiErrorException
     * @throws BadRequestException
     */
    private function createPayment(ArrayObject $details, PaymentInterface $paymentModel): Payment
    {
        try {
            if (
                $details->offsetExists('payment_id') &&
                $details->offsetExists('status') &&
                $details->offsetExists('is_live')
            ) {
                $this->abortPaymentProcessor->process($paymentModel);
                unset($details['status'], $details['payment_id'], $details['is_live']);
                // the parameter allow_save_card must be false when payment_method parameter is provided
                if (null !== $details['payment_method']) {
                    $details['allow_save_card'] = false;
                }
            }

            $this->logger->debug('[PayPlug] Create payment', [
                'detail' => $details->getArrayCopy(),
            ]);
            $payment = $this->payPlugApiClient->createPayment($details->getArrayCopy());
            $details['payment_id'] = $payment->id;
            $details['is_live'] = $payment->is_live;

            $this->logger->debug('[PayPlug] Create payment', [
                'payment_id' => $payment->id,
                'payment' => (array) $payment,
            ]);

            return $payment;
        } catch (BadRequestException $badRequestException) {
            $details['status'] = PayPlugApiClientInterface::FAILED;

            throw $badRequestException;
        } catch (\Throwable $throwable) {
            $details['status'] = PayPlugApiClientInterface::FAILED;

            throw new UnknownApiErrorException('payplug_sylius_payplug_plugin.error.api_unknow_error', $throwable->getCode(), $throwable);
        }
    }

    private function notifyErrors(ArrayObject $details, array $errorDetails): void
    {
        if (!isset($errorDetails['details'])) {
            return;
        }

        if (isset($errorDetails['details']['billing']['postcode'])) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.error.billing.postcode', [
                    '%postalCode%' => $details['billing']['postcode'],
                ]),
            );
        }

        if (isset($errorDetails['details']['shipping']['postcode'])) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.error.shipping.postcode', [
                    '%postalCode%' => $details['shipping']['postcode'],
                ]),
            );
        }

        if (
            !isset($errorDetails['details']['billing']['postcode']) &&
            !isset($errorDetails['details']['shipping']['postcode'])
        ) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.api_unknow_error');
        }
    }
}
