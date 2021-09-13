<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use DateTimeImmutable;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Payum\Core\Request\Generic;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

class PaymentNotificationHandler
{
    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $payplugCardRepository;

    /** @var \Sylius\Component\Resource\Factory\FactoryInterface */
    private $payplugCardFactory;

    /** @var \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface */
    private $flashBag;

    /** @var \Sylius\Component\Core\Repository\CustomerRepositoryInterface */
    private $customerRepository;

    public function __construct(
        LoggerInterface $logger,
        RepositoryInterface $payplugCardRepository,
        FactoryInterface $payplugCardFactory,
        CustomerRepositoryInterface $customerRepository,
        FlashBagInterface $flashBag
    ) {
        $this->logger = $logger;
        $this->payplugCardRepository = $payplugCardRepository;
        $this->payplugCardFactory = $payplugCardFactory;
        $this->flashBag = $flashBag;
        $this->customerRepository = $customerRepository;
    }

    public function treat(Generic $request, IVerifiableAPIResource $paymentResource, \ArrayObject $details): void
    {
        if (!$paymentResource instanceof Payment) {
            return;
        }

        if ($paymentResource->is_paid) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;
            $details['created_at'] = $paymentResource->created_at;

            $this->saveCard($request->getFirstModel(), $paymentResource);

            return;
        }

        if ($this->isResourceIsAuthorized($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;

            return;
        }

        if ($this->isRefusedOneyPayment($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY;

            $details['failure'] = [
                'code' => $paymentResource->failure->code ?? '',
                'message' => $paymentResource->failure->message ?? '',
            ];

            return;
        }

        $this->logger->info('[PayPlug] Notify action', ['failure' => $paymentResource->failure]);
        $details['failure'] = [
            'code' => $paymentResource->failure->code ?? '',
            'message' => $paymentResource->failure->message ?? '',
        ];
        $details['status'] = PayPlugApiClientInterface::FAILED;
    }

    private function saveCard(PaymentInterface $payment, IVerifiableAPIResource $paymentResource): void
    {
        if (!$paymentResource instanceof Payment) {
            return;
        }

        if (
            !$paymentResource->__isset('metadata') ||
            null === $paymentResource->__get('metadata') ||
            !isset($paymentResource->__get('metadata')['customer_id']) ||
            !\is_int($paymentResource->__get('metadata')['customer_id'])
        ) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\CustomerInterface|null $customer */
        $customer = $this->customerRepository->find($paymentResource->__get('metadata')['customer_id']);

        if (!$customer instanceof CustomerInterface) {
            return;
        }

        if (!$paymentResource->__isset('card') || null === $paymentResource->__get('card')) {
            return;
        }

        // Payment has been successfully made, but card was not saved
        if ($paymentResource->__get('card')->id === null) {
            $this->flashBag->add('info', 'payplug_sylius_payplug_plugin.warning.payment_success_no_card_saved');
            return;
        }

        $card = $this->payplugCardRepository->findOneBy([
            'externalId' => $paymentResource->__get('card')->id,
            'isLive' => $paymentResource->is_live,
        ]);

        if ($card instanceof Card) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        /** @var Card $card */
        $card = $this->payplugCardFactory->createNew();
        $card
            ->setCustomer($customer)
            ->setPaymentMethod($paymentMethod)
            ->setExternalId($paymentResource->__get('card')->id)
            ->setBrand($paymentResource->__get('card')->brand)
            ->setCountryCode($paymentResource->__get('card')->country)
            ->setLast4($paymentResource->__get('card')->last4)
            ->setExpirationMonth($paymentResource->__get('card')->exp_month)
            ->setExpirationYear($paymentResource->__get('card')->exp_year)
            ->setIsLive($paymentResource->is_live)
        ;

        $this->payplugCardRepository->add($card);
    }

    private function isResourceIsAuthorized(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney is reviewing the payer’s file
        if ($paymentResource->__isset('payment_method') &&
            $paymentResource->__get('payment_method') !== null &&
            $paymentResource->__get('payment_method')['is_pending'] === true) {
            return true;
        }

        $now = new DateTimeImmutable();
        if ($paymentResource->__isset('authorization') &&
            $paymentResource->__get('authorization') instanceof PaymentAuthorization &&
            $paymentResource->__get('authorization')->__get('expires_at') !== null &&
            $now < $now->setTimestamp($paymentResource->__get('authorization')->__get('expires_at'))) {
            return true;
        }

        return false;
    }

    private function isRefusedOneyPayment(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney has reviewed the payer’s file and refused it
        if (!$paymentResource->is_paid &&
            $paymentResource->__isset('payment_method') &&
            $paymentResource->__get('payment_method') !== null &&
            $paymentResource->__get('payment_method')['is_pending'] === false &&
            \in_array($paymentResource->__get('payment_method')['type'], OneyGatewayFactory::PAYMENT_CHOICES, true)
        ) {
            return true;
        }

        return false;
    }
}
