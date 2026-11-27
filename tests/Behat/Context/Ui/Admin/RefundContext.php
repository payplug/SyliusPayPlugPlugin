<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use PayPlug\SyliusPayPlugPlugin\Action\NotifyAction;
use Payum\Core\Request\Notify;
use Sylius\Behat\Context\Ui\Admin\ManagingOrdersContext;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\NotificationCheckerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;
use Tests\Sylius\RefundPlugin\Behat\Context\Ui\RefundingContext;

final class RefundContext implements Context
{
    /** @var NotifyAction */
    private $notifyAction;

    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var ManagingOrdersContext */
    private $managingOrdersContext;

    /** @var RefundingContext */
    private $refundingContext;

    /** @var NotificationCheckerInterface */
    private $notificationChecker;

    public function __construct(
        PayPlugApiMocker $payPlugApiMocker,
        ManagingOrdersContext $managingOrdersContext,
        RefundingContext $refundingContext,
        NotifyAction $notifyAction,
        NotificationCheckerInterface $notificationChecker,
    ) {
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->managingOrdersContext = $managingOrdersContext;
        $this->refundingContext = $refundingContext;
        $this->notifyAction = $notifyAction;
        $this->notificationChecker = $notificationChecker;
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
        string $paymentMethod,
    ): void {
        $this->payPlugApiMocker->mockApiRetrievePayment(function () use (
            $order,
            $unitNumber,
            $productName,
            $paymentMethod
        ) {
            $this->refundProduct($order, $unitNumber, $productName, $paymentMethod);
        });
    }

    /**
     * @When /^For (this order) I decide to refund (\d)st "([^"]+)" product with "([^"]+)" payment before the API refund window opens on "([^"]+)"$/
     */
    public function decideToRefundProductBeforeTheRefundWindowOpens(
        OrderInterface $order,
        int $unitNumber,
        string $productName,
        string $paymentMethod,
        string $refundableAfter,
    ): void {
        $this->payPlugApiMocker->mockApiRetrieveNotYetRefundablePayment(function () use (
            $order,
            $unitNumber,
            $productName,
            $paymentMethod
        ) {
            $this->refundProduct($order, $unitNumber, $productName, $paymentMethod);
        }, (new \DateTimeImmutable($refundableAfter))->getTimestamp());
    }

    /**
     * @When /^For (this order) I decide to refund (\d)st "([^"]+)" product with "([^"]+)" payment after the API refund window closed on "([^"]+)"$/
     */
    public function decideToRefundProductAfterTheRefundWindowClosed(
        OrderInterface $order,
        int $unitNumber,
        string $productName,
        string $paymentMethod,
        string $refundableUntil,
    ): void {
        $this->payPlugApiMocker->mockApiRetrieveNoLongerRefundablePayment(function () use (
            $order,
            $unitNumber,
            $productName,
            $paymentMethod
        ) {
            $this->refundProduct($order, $unitNumber, $productName, $paymentMethod);
        }, (new \DateTimeImmutable($refundableUntil))->getTimestamp());
    }

    /**
     * @When /^I refund totally (this order)'s from payplug portal$/
     */
    public function iRefundTotallyThisOrdersFromPayplugPortal(OrderInterface $order)
    {
        $this->payPlugApiMocker->mockApiRefundedFromPayPlugPortal(function () use ($order) {
            $notifyRequest = new Notify($order->getPayments()[0]);
            $this->notifyAction->setApi($this->payPlugApiMocker->getPayPlugApiClient());
            $this->notifyAction->execute($notifyRequest);
        });
    }

    /**
     * @When /^I refund partially (this order)'s from payplug portal with ([^"]+)$/
     */
    public function iRefundPartiallyThisOrdersFromPayplugPortal(OrderInterface $order, float $amount)
    {
        $this->payPlugApiMocker->mockApiRefundPartiallyFromPayPlugPortal(function () use ($order) {
            $notifyRequest = new Notify($order->getPayments()[0]);
            $this->notifyAction->setApi($this->payPlugApiMocker->getPayPlugApiClient());
            $this->notifyAction->execute($notifyRequest);
        }, (int) ($amount * 100));
    }

    /**
     * @Then I should see an error message :errorMessage
     */
    public function iShouldSeeAnErrorMessage(string $errorMessage)
    {
        $this->notificationChecker->checkNotification(
            $errorMessage,
            NotificationType::failure(),
        );
    }

    /**
     * @Then I should see a success message :successMessage
     */
    public function iShouldSeeASuccessMessage(string $successMessage)
    {
        $this->notificationChecker->checkNotification(
            $successMessage,
            NotificationType::success(),
        );
    }

    private function refundProduct(
        OrderInterface $order,
        int $unitNumber,
        string $productName,
        string $paymentMethod,
    ): void {
        $this->payPlugApiMocker->mockApiRefundedWithAmountPayment(function () use (
            $order,
            $unitNumber,
            $productName,
            $paymentMethod
        ) {
            $this->refundingContext->decidedToRefundProduct($unitNumber, $productName, $order->getNumber(), $paymentMethod);
        });
    }
}
