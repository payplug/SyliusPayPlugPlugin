<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Sylius\Behat\Context\Ui\Admin\ManagingOrdersContext;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;

final class RefundContext implements Context
{
    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var ManagingOrdersContext */
    private $managingOrdersContext;

    public function __construct(
        PayPlugApiMocker $payPlugApiMocker,
        ManagingOrdersContext $managingOrdersContext
    ) {
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->managingOrdersContext = $managingOrdersContext;
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
}
