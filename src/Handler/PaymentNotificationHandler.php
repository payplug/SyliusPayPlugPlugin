<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;

class PaymentNotificationHandler
{
    public function __construct(
        #[Autowire('@monolog.logger.payum')]
        private LoggerInterface $logger,
        private RepositoryInterface $payplugCardRepository,
        private FactoryInterface $payplugCardFactory,
        private CustomerRepositoryInterface $customerRepository,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private RequestStack $requestStack,
    ) {
    }

    public function treat(
        PaymentInterface $payment,
        IVerifiableAPIResource $paymentResource,
        \ArrayObject $details,
    ): void {
        if (!$paymentResource instanceof Payment) {
            return;
        }

        $lock = $this->lockFactory->createLock('payment_' . $paymentResource->id);
        $lock->acquire(true);

        $this->entityManager->refresh($payment);

        if ($details['status'] === PayPlugApiClientInterface::STATUS_ABORTED) {
            $lock->release();

            return;
        }

        if ($paymentResource->is_paid) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;
            $details['created_at'] = $paymentResource->created_at;

            $this->saveCard($payment, $paymentResource);

            $lock->release();

            return;
        }

        if ($this->isResourceIsAuthorized($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;

            $lock->release();

            return;
        }

        if ($this->isRefusedOneyPayment($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY;

            $details['failure'] = [
                'code' => $paymentResource->failure->code ?? '',
                'message' => $paymentResource->failure->message ?? '',
            ];

            $lock->release();

            return;
        }

        $this->logger->info('[PayPlug] Notify action', ['failure' => $paymentResource->failure]);
        $details['failure'] = [
            'code' => $paymentResource->failure->code ?? '',
            'message' => $paymentResource->failure->message ?? '',
        ];

        if (PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK === $details['status']) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.transaction_failed_1click');
        }

        $details['status'] = PayPlugApiClientInterface::FAILED;
        $lock->release();
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

        if (
            !$paymentResource->__isset('card') ||
            null === $paymentResource->__get('card') ||
            (null !== $paymentResource->__get('card') && null === $paymentResource->__get('card')->id)
        ) {
            return;
        }

        // Payment has been successfully made, but card was not saved
        if (null === $paymentResource->__get('card')->id) {
            $this->requestStack->getSession()->getFlashBag()->add('info', 'payplug_sylius_payplug_plugin.warning.payment_success_no_card_saved');

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
        if (
            $paymentResource->__isset('payment_method') &&
            null !== $paymentResource->__get('payment_method') &&
            \array_key_exists('is_pending', $paymentResource->__get('payment_method')) &&
            true === $paymentResource->__get('payment_method')['is_pending']
        ) {
            return true;
        }

        $now = new DateTimeImmutable();

        return $paymentResource->__isset('authorization') && $paymentResource->__get('authorization') instanceof PaymentAuthorization && null !== $paymentResource->__get('authorization')->__get('expires_at') && $now < $now->setTimestamp($paymentResource->__get('authorization')->__get('expires_at'));
    }

    private function isRefusedOneyPayment(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney has reviewed the payer’s file and refused it
        return !$paymentResource->is_paid && $paymentResource->__isset('payment_method') && null !== $paymentResource->__get('payment_method') && \array_key_exists('is_pending', $paymentResource->__get('payment_method')) && false === $paymentResource->__get('payment_method')['is_pending'] && \in_array($paymentResource->__get('payment_method')['type'], OneyGatewayFactory::PAYMENT_CHOICES, true);
    }
}
