<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\ApiRefundException;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\MessageHandler\RefundPaymentGeneratedHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\RefundCreatorInterface;
use PayplugUnifiedCore\Contracts\ILock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RefundPaymentGeneratedHandlerTest extends TestCase
{
    private const REFUND_PAYMENT_ID = 7;

    private const PAYMENT_ID = 42;

    private EntityManagerInterface&MockObject $entityManager;

    private RefundHistoryRepositoryInterface&MockObject $refundHistoryRepository;

    private StateMachineInterface&MockObject $stateMachine;

    private PayPlugApiClientInterface&MockObject $oneyClient;

    private RefundPayment&MockObject $refundPayment;

    private RefundPaymentGeneratedHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->refundHistoryRepository = $this->createMock(RefundHistoryRepositoryInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->oneyClient = $this->createMock(PayPlugApiClientInterface::class);
        $this->refundPayment = $this->createMock(RefundPayment::class);

        $refundPaymentRepository = $this->createMock(RepositoryInterface::class);
        $refundPaymentRepository->method('find')->with(self::REFUND_PAYMENT_ID)->willReturn($this->refundPayment);
        $refundPaymentRepository->method('findOneBy')->willReturn($this->refundPayment);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->method('find')->with(self::PAYMENT_ID)->willReturn($this->buildOneyPayment());

        $apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $apiClientFactory->method('createForPaymentMethod')->willReturn($this->oneyClient);

        $lock = $this->createMock(ILock::class);
        $lock->method('acquire')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $requestStack = $this->createMock(RequestStack::class);

        $this->handler = new RefundPaymentGeneratedHandler(
            $this->entityManager,
            $paymentRepository,
            $refundPaymentRepository,
            $this->refundHistoryRepository,
            $this->stateMachine,
            new RefundPaymentProcessor(
                $requestStack,
                $logger,
                $this->createMock(TranslatorInterface::class),
                $refundPaymentRepository,
                $this->refundHistoryRepository,
                $apiClientFactory,
                $this->createMock(RefundCreatorInterface::class),
                $lock,
            ),
            $logger,
            $requestStack,
        );
    }

    public function testOneyRefundRightAfterPaymentAndPreviousRefundIsSentToTheApi(): void
    {
        $this->refundHistoryRepository->method('findLastRefundForPayment')->willReturn(null);
        $this->oneyClient
            ->expects(self::once())
            ->method('refundPaymentWithAmount')
            ->with('pay_test_oney', 1000, self::REFUND_PAYMENT_ID)
            ->willReturn(Refund::fromAttributes(['id' => 're_test_1', 'amount' => 1000, 'metadata' => []]))
        ;
        $this->refundHistoryRepository->expects(self::once())->method('add');
        $this->stateMachine
            ->expects(self::once())
            ->method('apply')
            ->with($this->refundPayment, RefundPaymentTransitions::GRAPH, RefundPaymentTransitions::TRANSITION_COMPLETE)
        ;
        $this->entityManager->expects(self::once())->method('flush');

        ($this->handler)($this->buildMessage());
    }

    public function testOneyRefundRefusedByTheApiCompletesNothing(): void
    {
        $this->refundHistoryRepository->method('findLastRefundForPayment')->willReturn(null);
        $this->oneyClient
            ->method('refundPaymentWithAmount')
            ->willThrowException(new \RuntimeException('Refund is not allowed for this payment'))
        ;
        $this->refundHistoryRepository->expects(self::never())->method('add');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(ApiRefundException::class);

        ($this->handler)($this->buildMessage());
    }

    private function buildOneyPayment(): PaymentInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(OneyGatewayFactory::FACTORY_NAME);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturn(['payment_id' => 'pay_test_oney']);
        $payment->method('getCreatedAt')->willReturn(new \DateTime());

        return $payment;
    }

    private function buildMessage(): RefundPaymentGenerated
    {
        return new RefundPaymentGenerated(self::REFUND_PAYMENT_ID, '000000001', 1000, 'EUR', 3, self::PAYMENT_ID);
    }
}
