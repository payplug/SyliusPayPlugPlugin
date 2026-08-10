<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\NullHostedFieldsPaymentProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class NullHostedFieldsPaymentProcessorTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private NullHostedFieldsPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->processor = new NullHostedFieldsPaymentProcessor($this->logger);
    }

    public function testProcess_logsAndStoresDetailsWithoutCallingAnyApi(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn(['existing' => 'value']);

        $payment->expects(self::once())
            ->method('setDetails')
            ->with([
                'existing' => 'value',
                'hosted_fields_token' => 'hf_token_123',
                'hosted_fields_selected_brand' => 'CB',
                'hosted_fields_save_card' => true,
                'status' => PaymentInterface::STATE_PROCESSING,
            ])
        ;

        $this->logger->expects(self::once())->method('info');

        $this->processor->process($payment, 'hf_token_123', 'CB', true);
    }
}
