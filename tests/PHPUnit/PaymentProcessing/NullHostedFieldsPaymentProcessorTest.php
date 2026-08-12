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

    public function testProcess_storesCardMetadataAlongsideTokenAndBrand(): void
    {
        $this->logger->expects(self::once())->method('info');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['existing_key' => 'kept']);
        $payment->expects(self::once())->method('setDetails')->with([
            'existing_key' => 'kept',
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
            'hosted_fields_last4' => '4242',
            'hosted_fields_expiration_month' => 12,
            'hosted_fields_expiration_year' => 2030,
            'hosted_fields_country' => 'FR',
            'status' => PaymentInterface::STATE_PROCESSING,
        ]);

        $this->processor->process($payment, 'hf_token_abc', 'VISA', true, '4242', 12, 2030, 'FR');
    }
}
