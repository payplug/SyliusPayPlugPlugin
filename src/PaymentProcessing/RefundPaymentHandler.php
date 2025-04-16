<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Doctrine\Persistence\ObjectRepository;
use Payplug\Resource\Refund;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\RefundPlugin\Calculator\UnitRefundTotalCalculatorInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\Model\OrderItemUnitRefund;
use Sylius\RefundPlugin\Model\RefundType;
use Sylius\RefundPlugin\Model\ShipmentRefund;
use Sylius\RefundPlugin\Model\UnitRefundInterface;
use Sylius\RefundPlugin\Provider\RemainingTotalProviderInterface;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;
use Webmozart\Assert\Assert;

final class RefundPaymentHandler implements RefundPaymentHandlerInterface
{
    public function __construct(
        private UnitRefundTotalCalculatorInterface $unitRefundTotalCalculator,
        private RemainingTotalProviderInterface $remainingTotalProvider,
        private ObjectRepository $refundPaymentRepository,
        private RefundPaymentCompletedStateApplierInterface $refundPaymentCompletedStateApplier,
    ) {
    }

    public function handle(Refund $refund, PaymentInterface $payment): RefundUnits
    {
        return $this->fromRequest($refund, $payment);
    }

    public function fromRequest(Refund $refund, PaymentInterface $payment): RefundUnits
    {
        Assert::notNull($payment->getMethod());
        Assert::notNull($payment->getOrder());
        Assert::notNull($payment->getOrder()->getNumber(), 'Refunded order number not provided');

        $itemsRemainingPrice = $this->getItemsRemainingPrice($payment->getOrder());
        $shippingRemainingPrice = $this->getShippingRemainingPrice($payment->getOrder());
        [$items, $shipments] = $this->dispatchRefundPrice($itemsRemainingPrice, $shippingRemainingPrice, $refund->amount);

        if (0 === count($items) && 0 === count($shipments)) {
            throw new InvalidRefundAmount();
        }

        return new RefundUnits(
            $payment->getOrder()->getNumber(),
            $this->parseIdsToUnitRefunds($items, RefundType::orderItemUnit(), OrderItemUnitRefund::class),
            $this->parseIdsToUnitRefunds($shipments, RefundType::shipment(), ShipmentRefund::class),
            $payment->getMethod()->getId(),
            '',
        );
    }

    public function updatePaymentStatus(PaymentInterface $payment): void
    {
        Assert::isInstanceOf($payment->getOrder(), OrderInterface::class);

        /** @var RefundPaymentInterface[] $refundPayments */
        $refundPayments = $this->refundPaymentRepository->findBy([
            'orderNumber' => $payment->getOrder()->getNumber(),
            'state' => RefundPaymentInterface::STATE_NEW,
            'paymentMethod' => $payment->getMethod(),
        ]);

        foreach ($refundPayments as $refundPayment) {
            $this->refundPaymentCompletedStateApplier->apply($refundPayment);
        }
    }

    /**
     * Parse shipment id's to ShipmentRefund with id and remaining total or amount passed in request.
     *
     * @return array|UnitRefundInterface[]
     */
    private function parseIdsToUnitRefunds(array $units, RefundType $refundType, string $unitRefundClass): array
    {
        $refundUnits = [];
        foreach ($units as $id => $unit) {
            $total = $this
                ->unitRefundTotalCalculator
                ->calculateForUnitWithIdAndType($id, $refundType, $this->getAmount($unit))
            ;

            $refundUnits[] = new $unitRefundClass((int) $id, $total);
        }

        return $refundUnits;
    }

    private function getAmount(array $unit): ?float
    {
        if (isset($unit['full'])) {
            return null;
        }

        Assert::keyExists($unit, 'amount');

        return (float) $unit['amount'];
    }

    private function getItemsRemainingPrice(?OrderInterface $order): array
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        $items = [];

        /** @var OrderItemInterface $orderItem */
        foreach ($order->getItems() as $orderItem) {
            foreach ($orderItem->getUnits() as $itemUnit) {
                $items[$itemUnit->getId()] = $this->remainingTotalProvider->getTotalLeftToRefund(
                    $itemUnit->getId(),
                    RefundType::orderItemUnit(),
                );
            }
        }

        return $items;
    }

    private function getShippingRemainingPrice(?OrderInterface $order): array
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        $items = [];

        /** @var ShipmentInterface $shipment */
        foreach ($order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT) as $shipment) {
            $items[$shipment->getId()] = $this->remainingTotalProvider->getTotalLeftToRefund(
                $shipment->getId(),
                RefundType::shipment(),
            );
        }

        return $items;
    }

    private function dispatchRefundPrice(array $itemsRemainingPrice, array $shipmentRemainingPrince, int $amount): array
    {
        $items = $this->priceMatchRefundAmount($itemsRemainingPrice, $amount);

        //a line of order match exactly le refund amount so we refund it
        if (null !== $items) {
            return [$items, []];
        }

        $shipments = $this->priceMatchRefundAmount($shipmentRemainingPrince, $amount);

        //a shipment of order match exactly le refund amount so we refund it
        if (null !== $shipments) {
            return [[], $shipments];
        }

        $items = [];
        $shipments = [];

        //we haven't find the exact price so we partialy refund gradualy
        //in product ...
        foreach ($itemsRemainingPrice as $itemId => $price) {
            if ($price >= $amount) {
                return [$this->addItem($items, $itemId, (int) $amount), $shipments];
            }

            if (0 !== $price) {
                $items = $this->addItem($items, $itemId, $price);
                $amount -= $price;
            }

            if (0 === $amount) {
                return [$items, $shipments];
            }
        }

        //... and in shipment
        foreach ($shipmentRemainingPrince as $itemId => $price) {
            if ($price >= $amount) {
                return [$items, $this->addItem($shipments, $itemId, (int) $amount)];
            }

            $shipments = $this->addItem($shipments, $itemId, $price);
            $amount -= $price;

            if (0 === $amount) {
                return [$items, $shipments];
            }
        }

        return [$items, $shipments];
    }

    private function priceMatchRefundAmount(array $items, int $amount): ?array
    {
        foreach ($items as $itemId => $price) {
            if ($price === $amount) {
                return $this->addItem([], $itemId, $amount);
            }
        }

        return null;
    }

    private function addItem(array $array, int $itemId, int $amount): array
    {
        $array[$itemId] = ['amount' => \round($amount / 100, 2)];

        return $array;
    }
}
