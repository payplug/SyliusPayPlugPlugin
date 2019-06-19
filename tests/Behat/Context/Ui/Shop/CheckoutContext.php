<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Behat\Page\Shop\Order\ShowPageInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;
use Sylius\Behat\Page\Shop\Checkout\CompletePageInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Shop\Payum\PaymentPageInterface;

final class CheckoutContext implements Context
{
    /** @var CompletePageInterface */
    private $summaryPage;

    /** @var ShowPageInterface */
    private $orderDetails;

    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var PaymentPageInterface */
    private $paymentPage;

    public function __construct(
        CompletePageInterface $summaryPage,
        ShowPageInterface $orderDetails,
        PayPlugApiMocker $payPlugApiMocker,
        PaymentPageInterface $paymentPage
    ) {
        $this->summaryPage = $summaryPage;
        $this->orderDetails = $orderDetails;
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->paymentPage = $paymentPage;
    }

    /**
     * @When I confirm my order with PayPlug payment
     * @Given I have confirmed my order with PayPlug payment
     */
    public function iConfirmMyOrderWithPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockApiCreatePayment(function () {
            $this->summaryPage->confirmOrder();
        });
    }

    /**
     * @When I sign in to PayPlug and pay successfully
     */
    public function iSignInToPayPlugAndPaySuccessfully(): void
    {
        $this->payPlugApiMocker->mockApiSuccessfulPayment(function () {
            $this->paymentPage->notify(['id' => 1]);
            $this->paymentPage->capture();
        });
    }

    /**
     * @Given I have failed PayPlug payment
     */
    public function iHaveFailedPayPlugPayment()
    {
        $this->payPlugApiMocker->mockApiFailedPayment(function () {
            $this->paymentPage->notify(['id' => 1]);
            $this->paymentPage->capture();
        });
    }

    /**
     * @When I cancel my PayPlug payment
     * @Given I have cancelled PayPlug payment
     */
    public function iCancelMyPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockApiCancelledPayment(function () {
            $this->paymentPage->capture(['status' => PayPlugApiClientInterface::STATUS_CANCELED]);
        });
    }

    /**
     * @When I try to pay again PayPlug payment
     */
    public function iTryToPayAgainPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockApiCreatePayment(function () {
            $this->orderDetails->pay();
        });
    }
}
