<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\MessageHandler;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Exception\ApiRefundException;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Webmozart\Assert\Assert;

final class RefundPaymentGeneratedHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentRepositoryInterface $paymentRepository,
        private RepositoryInterface $refundPaymentRepository,
        private RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        // private FactoryInterface $stateMachineFactory,
        private RefundPaymentProcessor $refundPaymentProcessor,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private OrderRepositoryInterface $orderRepository,
        private TranslatorInterface $translator
    ) {
    }

    public function __invoke(RefundPaymentGenerated $message): void
    {
        try {
            /** @var PaymentInterface $payment */
            $payment = $this->paymentRepository->find($message->paymentId());
            $paymentMethod = $payment->getMethod();

            if (null === $paymentMethod) {
                return;
            }

            if (
                !$paymentMethod instanceof PaymentMethodInterface ||
                !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
                !\in_array($paymentMethod->getGatewayConfig()->getFactoryName(), [
                    PayPlugGatewayFactory::FACTORY_NAME,
                    OneyGatewayFactory::FACTORY_NAME,
                    BancontactGatewayFactory::FACTORY_NAME,
                    ApplePayGatewayFactory::FACTORY_NAME,
                    AmericanExpressGatewayFactory::FACTORY_NAME,
                ], true)
            ) {
                return;
            }

            $refundHistory = $this->payplugRefundHistoryRepository->findLastRefundForPayment($payment);
            if ($refundHistory instanceof RefundHistory &&
                null !== $refundHistory->getExternalId() &&
                null === $refundHistory->getRefundPayment()
            ) {
                /** @var RefundPayment $refundPayment */
                $refundPayment = $this->refundPaymentRepository->find($message->id());
                $stateMachine = $this->stateMachineFactory->get($refundPayment, RefundPaymentTransitions::GRAPH);
                $stateMachine->apply(RefundPaymentTransitions::TRANSITION_COMPLETE);
                $this->entityManager->flush();

                $refundHistory->setProcessed(true);
                $this->payplugRefundHistoryRepository->add($refundHistory);

                return;
            }

            $this->checkOneyRequirements($payment, $message);

            $this->processRefund($payment, $message);
        } catch (InvalidRefundAmount $exception) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $exception->getMessage());
            $this->logger->error($exception->getMessage());

            throw new ApiRefundException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (Throwable $throwable) {
            $this->logger->critical($throwable->getMessage());

            throw new ApiRefundException($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }

    private function processRefund(PaymentInterface $payment, RefundPaymentGenerated $message): void
    {
        $this->refundPaymentProcessor->processWithAmount($payment, $message->amount(), $message->id());

        /** @var RefundPayment $refundPayment */
        $refundPayment = $this->refundPaymentRepository->find($message->id());
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
        Assert::isInstanceOf($payment->getMethod()->getGatewayConfig(), GatewayConfigInterface::class);

        if (OneyGatewayFactory::FACTORY_NAME === $payment->getMethod()->getGatewayConfig()->getFactoryName() &&
            $this->hasLessThanFortyEightHoursTransaction($payment, $message->orderNumber())) {
            throw InvalidRefundAmount::withValidationConstraint($this->translator->trans('payplug_sylius_payplug_plugin.ui.oney_transaction_less_than_forty_eight_hours'));
        }
    }
}
