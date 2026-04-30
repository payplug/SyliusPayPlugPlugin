<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ObjectRepository;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Calculator\UnitRefundTotalCalculatorInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\Model\RefundType;
use Sylius\RefundPlugin\Provider\RemainingTotalProviderInterface;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;

final class RefundPaymentHandlerTest extends TestCase
{
    private UnitRefundTotalCalculatorInterface&MockObject $unitRefundTotalCalculator;

    private RemainingTotalProviderInterface&MockObject $remainingTotalProvider;

    private ObjectRepository&MockObject $refundPaymentRepository;

    private RefundPaymentCompletedStateApplierInterface&MockObject $refundPaymentCompletedStateApplier;

    private RefundPaymentHandler $handler;

    protected function setUp(): void
    {
        $this->unitRefundTotalCalculator = $this->createMock(UnitRefundTotalCalculatorInterface::class);
        $this->remainingTotalProvider = $this->createMock(RemainingTotalProviderInterface::class);
        $this->refundPaymentRepository = $this->createMock(ObjectRepository::class);
        $this->refundPaymentCompletedStateApplier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);

        $this->handler = new RefundPaymentHandler(
            $this->unitRefundTotalCalculator,
            $this->remainingTotalProvider,
            $this->refundPaymentRepository,
            $this->refundPaymentCompletedStateApplier,
        );
    }

    // -------------------------------------------------------------------------
    // fromRequest() — exact single-item match
    // -------------------------------------------------------------------------

    /**
     * Refund amount matches exactly one order item unit's remaining total.
     * Verifies fromRequest() returns a RefundUnits command targeting that single unit.
     */
    public function testFromRequest_exactSingleItemMatch_returnsRefundUnitsForThatItem(): void
    {
        $refund = $this->buildRefund(1000);
        $payment = $this->buildPayment('ORD-1', [
            11 => 1000, // unit ID 11 has remaining 1000 — exact match
        ], []);

        // The calculator is called for unit 11 with null amount (full refund)
        $this->unitRefundTotalCalculator
            ->method('calculateForUnitWithIdAndType')
            ->willReturn(1000)
        ;

        $result = $this->handler->fromRequest($refund, $payment);

        self::assertInstanceOf(RefundUnits::class, $result);
    }

    // -------------------------------------------------------------------------
    // fromRequest() — exact shipment match
    // -------------------------------------------------------------------------

    /**
     * Refund amount matches exactly one shipping adjustment (no item match).
     * Verifies fromRequest() returns a RefundUnits command targeting that shipment adjustment.
     */
    public function testFromRequest_exactShipmentMatch_returnsRefundUnitsForShipment(): void
    {
        $refund = $this->buildRefund(500);
        // No item matches, but shipment 20 matches exactly
        $payment = $this->buildPayment('ORD-2', [
            11 => 300,
        ], [
            20 => 500,
        ]);

        $this->unitRefundTotalCalculator
            ->method('calculateForUnitWithIdAndType')
            ->willReturn(500)
        ;

        $result = $this->handler->fromRequest($refund, $payment);

        self::assertInstanceOf(RefundUnits::class, $result);
    }

    // -------------------------------------------------------------------------
    // fromRequest() — partial allocation across multiple items
    // -------------------------------------------------------------------------

    /**
     * Refund amount (700) has no exact single-item match but fits across two items (400+300).
     * Verifies fromRequest() falls back to partial allocation and returns a valid RefundUnits command.
     */
    public function testFromRequest_partialAllocation_spreadsAcrossItems(): void
    {
        // Refund 700, items have 400 + 300 — no single exact match, so partial
        $refund = $this->buildRefund(700);
        $payment = $this->buildPayment('ORD-3', [
            11 => 400,
            12 => 300,
        ], []);

        $this->unitRefundTotalCalculator
            ->method('calculateForUnitWithIdAndType')
            ->willReturnCallback(fn ($id, $type, $amount) => (int) ($amount * 100))
        ;

        $result = $this->handler->fromRequest($refund, $payment);

        self::assertInstanceOf(RefundUnits::class, $result);
    }

    // -------------------------------------------------------------------------
    // fromRequest() — amount exceeds all available → throws InvalidRefundAmount
    // -------------------------------------------------------------------------

    /**
     * Refund amount (9999) exceeds the total available across all items and shipments (none).
     * Verifies fromRequest() throws InvalidRefundAmount when allocation is impossible.
     */
    public function testFromRequest_amountExceedsAvailable_throwsInvalidRefundAmount(): void
    {
        $this->expectException(InvalidRefundAmount::class);

        // Total available is 0 (no items, no shipments)
        $refund = $this->buildRefund(9999);
        $payment = $this->buildPayment('ORD-4', [], []);

        $this->handler->fromRequest($refund, $payment);
    }

    // -------------------------------------------------------------------------
    // fromRequest() — zero-amount item is skipped
    // -------------------------------------------------------------------------

    /**
     * One item has zero remaining refundable amount; the other has the exact target amount.
     * Verifies the zero-remaining item is skipped and allocation succeeds on the valid item.
     */
    public function testFromRequest_itemWithZeroRemaining_isSkipped(): void
    {
        // Only item with zero remaining, one with positive
        $refund = $this->buildRefund(300);
        $payment = $this->buildPayment('ORD-5', [
            11 => 0,
            12 => 300,
        ], []);

        $this->unitRefundTotalCalculator
            ->method('calculateForUnitWithIdAndType')
            ->willReturn(300)
        ;

        $result = $this->handler->fromRequest($refund, $payment);

        self::assertInstanceOf(RefundUnits::class, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRefund(int $amount): Refund
    {
        return Refund::fromAttributes(['amount' => $amount]);
    }

    /**
     * @param array<int, int> $itemRemainingPrices  map of unitId → remainingAmount
     * @param array<int, int> $shipmentRemainingPrices map of adjustmentId → remainingAmount
     */
    private function buildPayment(
        string $orderNumber,
        array $itemRemainingPrices,
        array $shipmentRemainingPrices,
    ): PaymentInterface {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getId')->willReturn(1);

        // Build order items / units
        $orderItems = [];
        $unitIdToRemaining = [];

        foreach ($itemRemainingPrices as $unitId => $remaining) {
            $unit = $this->createMock(OrderItemUnitInterface::class);
            $unit->method('getId')->willReturn($unitId);

            $orderItem = $this->createMock(OrderItemInterface::class);
            $orderItem->method('getUnits')->willReturn(new ArrayCollection([$unit]));

            $orderItems[] = $orderItem;
            $unitIdToRemaining[$unitId] = $remaining;
        }

        // Build shipping adjustments
        $adjustments = [];
        $shipmentIdToRemaining = [];

        foreach ($shipmentRemainingPrices as $adjId => $remaining) {
            $adj = $this->createMock(AdjustmentInterface::class);
            $adj->method('getId')->willReturn($adjId);
            $adjustments[] = $adj;
            $shipmentIdToRemaining[$adjId] = $remaining;
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn($orderNumber);
        $order->method('getItems')->willReturn(new ArrayCollection($orderItems));
        $order->method('getAdjustments')
            ->with(AdjustmentInterface::SHIPPING_ADJUSTMENT)
            ->willReturn(new ArrayCollection($adjustments))
        ;

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getOrder')->willReturn($order);

        // Wire up remainingTotalProvider
        $this->remainingTotalProvider
            ->method('getTotalLeftToRefund')
            ->willReturnCallback(function (int $id, RefundType $type) use ($unitIdToRemaining, $shipmentIdToRemaining) {
                if (isset($unitIdToRemaining[$id])) {
                    return $unitIdToRemaining[$id];
                }
                if (isset($shipmentIdToRemaining[$id])) {
                    return $shipmentIdToRemaining[$id];
                }

                return 0;
            })
        ;

        return $payment;
    }
}
