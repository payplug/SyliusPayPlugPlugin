<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Handler;

use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;

final class HostedFieldsWebhookNotificationHandlerTest extends TestCase
{
    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

    private HostedFieldsWebhookNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);

        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
        );
    }

    private function payment(int $id = 42): PaymentInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn($id);

        return $payment;
    }

    public function testTreat_onValidNotification_savesTreatsAndAppliesTheOutcomeAgainstTheResolvedPayment(): void
    {
        // orderId here ("000000059") is deliberately unrelated to the Sylius payment id (42) —
        // unlike NotifyHostedPaymentRequestHandler, this handler never cross-checks it, since the
        // caller (IpnAction) already resolved $payment by the webhook's own payment id.
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000059', 'amount' => 1000]);

        $this->configurationRepository->method('get')->with('payplug_webhook_authorization_header')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_123');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->treat($this->payment(42), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_whenAlreadyTreated_isIdempotentAndDoesNotReapply(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000059', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->treat($this->payment(), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onInvalidSignature_throwsInvalidNotificationExceptionWithoutApplyingTheOutcome(): void
    {
        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->expectException(InvalidNotificationException::class);

        $this->handler->treat($this->payment(), '{}', ['Authorization' => 'Bearer wrong-secret']);
    }
}
