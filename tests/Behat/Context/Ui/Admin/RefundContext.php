<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Sylius\Behat\Context\Ui\Admin\ManagingOrdersContext;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;
use Tests\Sylius\RefundPlugin\Behat\Context\Ui\RefundingContext;

final class RefundContext implements Context
{
    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var ManagingOrdersContext */
    private $managingOrdersContext;

    /** @var \Tests\Sylius\RefundPlugin\Behat\Context\Ui\RefundingContext */
    private $refundingContext;

    public function __construct(
        PayPlugApiMocker $payPlugApiMocker,
        ManagingOrdersContext $managingOrdersContext,
        RefundingContext $refundingContext
    ) {
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->managingOrdersContext = $managingOrdersContext;
        $this->refundingContext = $refundingContext;
    }

    /**
     * @When /^I mark (this order)'s payplug payment as refunded$/
     */
    public function iMarkThisOrdersPayPlugPaymentAsRefunded(OrderInterface $order): void
    {
        $this->payPlugApiMocker->mockApiRefundedPayment(function () use ($order) {
            $this->managingOrdersContext->iMarkThisOrderSPaymentAsRefunded($order);
        });
    }

    /**
     * @When /^For (this order) I decide to refund (\d)st "([^"]+)" product with "([^"]+)" payment$/
     */
    public function decideToRefundProduct(
        OrderInterface $order,
        int $unitNumber,
        string $productName,
        string $paymentMethod
    ): void {
        $this->payPlugApiMocker->mockApiRefundedWithAmountPayment(function () use (
            $order, $unitNumber, $productName, $paymentMethod
        ) {
            $this->refundingContext->decidedToRefundProduct($unitNumber, $productName, $order->getNumber(), $paymentMethod);
        });
    }

    /**
     * @When I want to refund some units of order :orderNumber
     */
    public function wantToRefundSomeUnitsOfOrder(string $orderNumber): void
    {
        $this->refundingContext->wantToRefundSomeUnitsOfOrder($orderNumber);
    }

    /**
     * @Then I should still be able to refund order shipment with :paymentMethodName payment
     */
    public function shouldStillBeAbleToRefundOrderShipment(): void
    {
        $this->refundingContext->shouldStillBeAbleToRefundOrderShipment();
    }

    /**
     * @Then this order refunded total should (still) be :refundedTotal
     */
    public function refundedTotalShouldBe(string $refundedTotal): void
    {
        $this->refundingContext->refundedTotalShouldBe($refundedTotal);
    }

    /**
     * @When I decide to refund all units of this order with :paymentMethod payment
     */
    public function decideToRefundAllUnits(string $paymentMethod): void
    {
        $this->refundingContext->decideToRefundAllUnits($paymentMethod);
    }

    /**
     * @Then I should not be able to refund anything
     */
    public function iShouldNotBeAbleToRefundAnything(): void
    {
        $this->refundingContext->iShouldNotBeAbleToRefundAnything();
    }

    /**
     * @Then I should be able to refund :count :productName products
     */
    public function shouldBeAbleToRefundProducts(int $count, string $productName): void
    {
        $this->refundingContext->shouldBeAbleToRefundProducts($count, $productName);
    }
}
