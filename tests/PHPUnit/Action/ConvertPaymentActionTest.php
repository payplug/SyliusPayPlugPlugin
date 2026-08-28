<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action;

use ArrayObject;
use PayPlug\SyliusPayPlugPlugin\Action\ConvertPaymentAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentActionTest extends TestCase
{
    private PayPlugPaymentDataCreator&MockObject $paymentDataCreator;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private ConvertPaymentAction $action;

    protected function setUp(): void
    {
        $this->paymentDataCreator = $this->createMock(PayPlugPaymentDataCreator::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);

        $this->action = new ConvertPaymentAction($this->paymentDataCreator);
        $this->action->setApi($this->apiClient);
    }

    // -------------------------------------------------------------------------
    // supports()
    // -------------------------------------------------------------------------

    /**
     * Passes a Convert request with a PaymentInterface source and 'array' as the target format.
     * Verifies supports() returns true.
     */
    public function testSupports_withConvertRequestAndPaymentSource_returnsTrue(): void
    {
        $request = $this->createMock(Convert::class);
        $request->method('getSource')->willReturn($this->createMock(PaymentInterface::class));
        $request->method('getTo')->willReturn('array');

        self::assertTrue($this->action->supports($request));
    }

    /**
     * Source is a plain stdClass instead of a PaymentInterface.
     * Verifies supports() returns false.
     */
    public function testSupports_withConvertRequestButNonPaymentSource_returnsFalse(): void
    {
        $request = $this->createMock(Convert::class);
        $request->method('getSource')->willReturn(new \stdClass());
        $request->method('getTo')->willReturn('array');

        self::assertFalse($this->action->supports($request));
    }

    /**
     * Source is a valid PaymentInterface but the target format is 'json' instead of 'array'.
     * Verifies supports() returns false.
     */
    public function testSupports_withConvertRequestButWrongTo_returnsFalse(): void
    {
        $request = $this->createMock(Convert::class);
        $request->method('getSource')->willReturn($this->createMock(PaymentInterface::class));
        $request->method('getTo')->willReturn('json');

        self::assertFalse($this->action->supports($request));
    }

    // -------------------------------------------------------------------------
    // execute() — delegates to creator and sets result
    // -------------------------------------------------------------------------

    /**
     * Calls execute() and verifies the action delegates to PayPlugPaymentDataCreator::create() exactly once.
     * The ArrayObject returned by the creator is converted to an array and passed to setResult().
     */
    public function testExecute_delegatesToCreatorAndSetsResult(): void
    {
        $payment = $this->createMock(PaymentInterface::class);

        $createdDetails = new ArrayObject([
            'amount' => 1500,
            'currency' => 'EUR',
        ]);

        $this->paymentDataCreator
            ->expects(self::once())
            ->method('create')
            ->with($payment)
            ->willReturn($createdDetails)
        ;

        $request = $this->createMock(Convert::class);
        $request->method('getSource')->willReturn($payment);
        $request->method('getTo')->willReturn('array');
        $request->expects(self::once())
            ->method('setResult')
            ->with(['amount' => 1500, 'currency' => 'EUR'])
        ;

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — result contains all fields from creator
    // -------------------------------------------------------------------------

    /**
     * The creator returns an ArrayObject with multiple nested fields (amount, payment_method, metadata).
     * Verifies all fields are present and correctly typed in the array passed to setResult().
     */
    public function testExecute_passesAllCreatorFieldsToResult(): void
    {
        $payment = $this->createMock(PaymentInterface::class);

        $createdDetails = new ArrayObject([
            'amount' => 2000,
            'currency' => 'EUR',
            'payment_method' => 'bancontact',
            'metadata' => ['customer_id' => 42, 'order_number' => 'ORD-99'],
        ]);

        $this->paymentDataCreator->method('create')->willReturn($createdDetails);

        $capturedResult = null;
        $request = $this->createMock(Convert::class);
        $request->method('getSource')->willReturn($payment);
        $request->method('getTo')->willReturn('array');
        $request->method('setResult')->willReturnCallback(function (array $result) use (&$capturedResult) {
            $capturedResult = $result;
        });

        $this->action->execute($request);

        self::assertSame(2000, $capturedResult['amount']);
        self::assertSame('bancontact', $capturedResult['payment_method']);
        self::assertSame(42, $capturedResult['metadata']['customer_id']);
    }
}
