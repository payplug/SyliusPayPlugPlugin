<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\MessageHandler;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Exception\ApiRefundException;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Webmozart\Assert\Assert;

final class RefundPaymentGeneratedHandler
{
    /** @var ObjectManager */
    private $entityManager;

    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var RefundPaymentProcessor */
    private $refundPaymentProcessor;

    /** @var PaymentRepositoryInterface */
    private $paymentRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var RefundHistoryRepositoryInterface */
    private $payplugRefundHistoryRepository;

    /** @var Session */
    private $session;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(
        EntityManagerInterface $entityManager,
        PaymentRepositoryInterface $paymentRepository,
        RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        FactoryInterface $stateMachineFactory,
        RefundPaymentProcessor $refundPaymentProcessor,
        LoggerInterface $logger,
        Session $session,
        OrderRepositoryInterface $orderRepository,
        TranslatorInterface $translator
    ) {
        $this->entityManager = $entityManager;
        $this->paymentRepository = $paymentRepository;
        $this->payplugRefundHistoryRepository = $payplugRefundHistoryRepository;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->refundPaymentProcessor = $refundPaymentProcessor;
        $this->logger = $logger;
        $this->session = $session;
        $this->orderRepository = $orderRepository;
        $this->translator = $translator;
    }

    public function __invoke(RefundPaymentGenerated $message): void
    {
        try {
            /** @var PaymentInterface $payment */
            $payment = $this->paymentRepository->find($message->paymentId());
            /** @var PaymentMethodInterface|null $paymentMethod */
            $paymentMethod = $payment->getMethod();

            if (null === $paymentMethod) {
                return;
            }

            $gatewayName = $paymentMethod->getCode();

            if ($gatewayName !== PayPlugGatewayFactory::FACTORY_NAME && $gatewayName !== OneyGatewayFactory::FACTORY_NAME) {
                return;
            }

            $refundHistory = $this->payplugRefundHistoryRepository->findLastRefundForPayment($payment);
            if ($refundHistory instanceof RefundHistory &&
                $refundHistory->getExternalId() !== null &&
                $refundHistory->getRefundPayment() === null
            ) {
                /** @var RefundPayment $refundPayment */
                $refundPayment = $this->entityManager->getRepository(RefundPayment::class)->find($message->id());
                $stateMachine = $this->stateMachineFactory->get($refundPayment, RefundPaymentTransitions::GRAPH);
                $stateMachine->apply(RefundPaymentTransitions::TRANSITION_COMPLETE);
                $this->entityManager->flush();

                $refundHistory->setProcessed(true);
                $this->payplugRefundHistoryRepository->add($refundHistory);

                return;
            }

            $this->checkOneyRequirements($payment, $message);

            $this->processRefund($payment, $message);
        } catch (Throwable $throwable) {
            $this->logger->critical($throwable->getMessage());

            throw new ApiRefundException($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }

    private function processRefund(PaymentInterface $payment, RefundPaymentGenerated $message): void
    {
        $this->refundPaymentProcessor->processWithAmount($payment, $message->amount(), $message->id());

        /** @var RefundPayment $refundPayment */
        $refundPayment = $this->entityManager->getRepository(RefundPayment::class)->find($message->id());
        $stateMachine = $this->stateMachineFactory->get($refundPayment, RefundPaymentTransitions::GRAPH);
        $stateMachine->apply(RefundPaymentTransitions::TRANSITION_COMPLETE);

        $this->entityManager->flush();
    }

    private function hasLessThanFortyEightHoursTransaction(PaymentInterface $payment, string $orderNumber): bool
    {
        $now = new DateTime();

        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

        Assert::isInstanceOf($order->getLastPayment(), PaymentInterface::class);
        Assert::isInstanceOf($order->getLastPayment()->getCreatedAt(), DateTimeInterface::class);

        /** @var RefundHistory|null $refundHistory */
        $refundHistory = $this->payplugRefundHistoryRepository->findLastProcessedRefundForPayment($payment);
        if (!$refundHistory instanceof RefundHistory) {
            Assert::isInstanceOf($order->getLastPayment()->getCreatedAt(), DateTimeInterface::class);

            return $this->isLessThanFortyEightHours(
                $order->getLastPayment()->getCreatedAt(),
                $now
            );
        }

        if ($this->isLessThanFortyEightHours($order->getLastPayment()->getCreatedAt(), $now)) {
            return true;
        }

        return $this->isLessThanFortyEightHours(
            $refundHistory->getCreatedAt(),
            $now
        );
    }

    private function isLessThanFortyEightHours(DateTimeInterface $from, DateTimeInterface $to): bool
    {
        $diff = $to->diff($from);
        Assert::integer($diff->days);
        $hours = $diff->h + ($diff->days * 24);

        return $hours < OneyGatewayFactory::REFUND_WAIT_TIME_IN_HOURS;
    }

    private function checkOneyRequirements(
        PaymentInterface $payment,
        RefundPaymentGenerated $message
    ): void {
        Assert::isInstanceOf($payment->getMethod(), PaymentMethodInterface::class);

        if ($payment->getMethod()->getCode() === OneyGatewayFactory::FACTORY_NAME &&
            $this->hasLessThanFortyEightHoursTransaction($payment, $message->orderNumber())) {
            throw new ApiRefundException($this->translator->trans('payplug_sylius_payplug_plugin.ui.oney_transaction_less_than_forty_eight_hours'));
        }
    }
}
