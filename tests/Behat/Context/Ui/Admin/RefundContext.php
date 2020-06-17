<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use PayPlug\SyliusPayPlugPlugin\Action\NotifyAction;
use Payum\Core\Request\Notify;
use Sylius\Behat\Context\Ui\Admin\ManagingOrdersContext;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;

final class RefundContext implements Context
{
    /** @var NotifyAction */
    private $notifyAction;

    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var ManagingOrdersContext */
    private $managingOrdersContext;

    public function __construct(
        PayPlugApiMocker $payPlugApiMocker,
        ManagingOrdersContext $managingOrdersContext,
        NotifyAction $notifyAction
    ) {
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->managingOrdersContext = $managingOrdersContext;
        $this->notifyAction = $notifyAction;
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
}
