<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Controller\IpnAction;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\Request;

final class IpnActionTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private PaymentNotificationHandler&MockObject $paymentNotificationHandler;

    private RefundNotificationHandler&MockObject $refundNotificationHandler;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PaymentRepositoryInterface&MockObject $paymentRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private IpnAction $action;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paymentNotificationHandler = $this->createMock(PaymentNotificationHandler::class);
        $this->refundNotificationHandler = $this->createMock(RefundNotificationHandler::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new IpnAction(
            $this->logger,
            $this->paymentNotificationHandler,
            $this->refundNotificationHandler,
            $this->apiClientFactory,
            $this->paymentRepository,
            $this->entityManager,
        );
    }

    private function paymentWithGatewayConfig(): PaymentInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);

        return $payment;
    }

    public function testInvoke_forALegacyPayment_goesThroughTheSdk(): void
    {
        $payment = $this->paymentWithGatewayConfig();
        $this->paymentRepository->method('findOneByPayPlugPaymentId')->willReturn($payment);

        $request = Request::create('/payplug/ipn', 'POST', content: \json_encode(['id' => 'pay_1']));

        $this->apiClientFactory->expects(self::once())->method('create')->with(PayPlugGatewayFactory::FACTORY_NAME)
            ->willReturn($this->createMock(PayPlugApiClientInterface::class));

        $response = $this->action->__invoke($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvoke_whenPaymentIsNotFound_returnsUnauthorized(): void
    {
        $this->paymentRepository->method('findOneByPayPlugPaymentId')->willReturn(null);

        $request = Request::create('/payplug/ipn', 'POST', content: \json_encode(['id' => 'pay_1']));

        $response = $this->action->__invoke($request);

        self::assertSame(401, $response->getStatusCode());
    }
}
