<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Controller\IpnAction;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
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

    private HostedFieldsWebhookNotificationHandler&MockObject $hostedFieldsWebhookNotificationHandler;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PaymentRepositoryInterface&MockObject $paymentRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private IpnAction $action;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paymentNotificationHandler = $this->createMock(PaymentNotificationHandler::class);
        $this->refundNotificationHandler = $this->createMock(RefundNotificationHandler::class);
        $this->hostedFieldsWebhookNotificationHandler = $this->createMock(HostedFieldsWebhookNotificationHandler::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->action = new IpnAction(
            $this->logger,
            $this->paymentNotificationHandler,
            $this->refundNotificationHandler,
            $this->hostedFieldsWebhookNotificationHandler,
            $this->apiClientFactory,
            $this->paymentRepository,
            $this->entityManager,
        );
    }

    private function paymentWithGatewayConfig(bool $hostedFields): PaymentInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn([PayPlugGatewayFactory::HOSTED_FIELDS => $hostedFields]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);

        return $payment;
    }

    public function testInvoke_forAHostedFieldsPayment_delegatesToTheWebhookNotificationHandlerAndNeverTouchesTheLegacySdk(): void
    {
        $payment = $this->paymentWithGatewayConfig(hostedFields: true);
        $this->paymentRepository->method('findOneByPayPlugPaymentId')->with('pay_1')->willReturn($payment);

        $request = Request::create('/payplug/ipn', 'POST', content: \json_encode(['id' => 'pay_1', 'execCode' => '0000']));
        $request->headers->set('Authorization', 'Bearer shared-secret');

        $this->hostedFieldsWebhookNotificationHandler->expects(self::once())->method('treat')
            ->with($payment, $request->getContent(), self::callback(static fn (array $headers): bool => 'Bearer shared-secret' === ($headers['authorization'] ?? null)));
        $this->apiClientFactory->expects(self::never())->method('create');
        $this->entityManager->expects(self::never())->method('flush');

        $response = $this->action->__invoke($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvoke_forAHostedFieldsPayment_whenNotificationIsInvalid_logsAndStillReturns200(): void
    {
        $payment = $this->paymentWithGatewayConfig(hostedFields: true);
        $this->paymentRepository->method('findOneByPayPlugPaymentId')->willReturn($payment);
        $this->hostedFieldsWebhookNotificationHandler->method('treat')->willThrowException(new InvalidNotificationException('boom'));

        $request = Request::create('/payplug/ipn', 'POST', content: \json_encode(['id' => 'pay_1']));

        $this->logger->expects(self::once())->method('error');

        $response = $this->action->__invoke($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvoke_forALegacyPayment_stillGoesThroughTheSdkAndNeverCallsTheWebhookNotificationHandler(): void
    {
        $payment = $this->paymentWithGatewayConfig(hostedFields: false);
        $this->paymentRepository->method('findOneByPayPlugPaymentId')->willReturn($payment);

        $request = Request::create('/payplug/ipn', 'POST', content: \json_encode(['id' => 'pay_1']));

        $this->hostedFieldsWebhookNotificationHandler->expects(self::never())->method('treat');
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
