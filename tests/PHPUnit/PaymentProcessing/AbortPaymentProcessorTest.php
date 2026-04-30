<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AbortPaymentProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class AbortPaymentProcessorTest extends TestCase
{
    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private AbortPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);

        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($this->apiClient);

        $this->processor = new AbortPaymentProcessor($this->apiClientFactory);
    }

    // -------------------------------------------------------------------------
    // process() — no payment_id in details → no API call
    // -------------------------------------------------------------------------

    /**
     * The payment details array contains no payment_id key (payment was never created on PayPlug).
     * Verifies the API factory and abortPayment() are never called.
     */
    public function testProcess_withNoPaymentId_doesNotCallApi(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);
        $payment->method('getMethod')->willReturn($this->createMock(PaymentMethodInterface::class));

        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');
        $this->apiClient->expects(self::never())->method('abortPayment');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // process() — null method → no API call
    // -------------------------------------------------------------------------

    /**
     * A payment_id exists but getMethod() returns null (no payment method attached).
     * Verifies abortPayment() is never called (guard clause exits early).
     */
    public function testProcess_withNullMethod_doesNotCallApi(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['payment_id' => 'pay_abc']);
        $payment->method('getMethod')->willReturn(null);

        $this->apiClient->expects(self::never())->method('abortPayment');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // process() — valid payment_id → abortPayment called
    // -------------------------------------------------------------------------

    /**
     * A valid payment_id and payment method are present; the API client is wired correctly.
     * Verifies createForPaymentMethod() and abortPayment() are each called exactly once.
     */
    public function testProcess_withPaymentId_callsAbortPayment(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['payment_id' => 'pay_xyz']);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $this->apiClientFactory->expects(self::once())
            ->method('createForPaymentMethod')
            ->with($paymentMethod)
            ->willReturn($this->apiClient)
        ;
        $this->apiClient->expects(self::once())->method('abortPayment')->with('pay_xyz');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // process() — HttpException is silently swallowed
    // -------------------------------------------------------------------------

    /**
     * The API client throws an HttpException (e.g. payment already aborted on PayPlug's side).
     * Verifies process() completes without re-throwing (exception is intentionally swallowed).
     */
    public function testProcess_httpException_isSilentlySwallowed(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['payment_id' => 'pay_already_failed']);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $this->apiClient->method('abortPayment')
            ->willThrowException($this->buildHttpException())
        ;

        // Should not throw — HttpException is caught and ignored
        $this->processor->process($payment);
        self::assertTrue(true); // Reached without exception
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildHttpException(): HttpException
    {
        return new HttpException('Conflict', '{"object": "error"}', 409);
    }
}
