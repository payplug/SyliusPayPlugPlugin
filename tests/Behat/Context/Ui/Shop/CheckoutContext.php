<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Behat\Page\Shop\Checkout\CompletePageInterface;
use Sylius\Behat\Page\Shop\Order\ShowPageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Shop\Payum\PaymentPageInterface;
use Webmozart\Assert\Assert;

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
        PaymentPageInterface $paymentPage,
    ) {
        $this->summaryPage = $summaryPage;
        $this->orderDetails = $orderDetails;
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->paymentPage = $paymentPage;
    }

    /**
     * @Given I have confirmed my order with PayPlug payment
     * @When I confirm my order with PayPlug payment
     */
    public function iConfirmMyOrderWithPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockPayPlugApiGetGatewayFactoryName(function () {
            $this->payPlugApiMocker->mockApiCreatePayment(function () {
                $this->summaryPage->confirmOrder();
            });
        });
    }

    /**
     * @When I sign in to PayPlug and pay successfully
     */
    public function iSignInToPayPlugAndPaySuccessfully(): void
    {
        $this->payPlugApiMocker->mockMultipleApiCancelledPayment(function () {
            $this->payPlugApiMocker->mockPayPlugApiGetGatewayFactoryName(function () {
                $this->payPlugApiMocker->mockApiSuccessfulPayment(function () {
                    $this->paymentPage->notify(['id' => 1]);
                    $this->paymentPage->capture();
                });
            });
        });
    }

    /**
     * @Given I have failed PayPlug payment
     */
    public function iHaveFailedPayPlugPayment()
    {
        $this->payPlugApiMocker->mockMultipleApiCancelledPayment(function () {
            $this->payPlugApiMocker->mockApiFailedPayment(function () {
                $this->paymentPage->notify(['id' => 1]);
                $this->paymentPage->capture();
            });
        });
    }

    /**
     * @Given I have cancelled PayPlug payment
     * @When I cancel my PayPlug payment
     */
    public function iCancelMyPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockMultipleApiCancelledPayment(function () {
            $this->paymentPage->capture(['status' => PayPlugApiClientInterface::STATUS_CANCELED]);
        });
    }

    /**
     * @When I leave my PayPlug payment page
     */
    public function iLeaveMyPayPlugPaymentPage(): void
    {
        $this->payPlugApiMocker->mockApiCreatedPayment(function () {
            $this->paymentPage->capture(['status' => PayPlugApiClientInterface::STATUS_CREATED]);
        });
    }

    /**
     * @Given I have left PayPlug payment page for more than 15 minutes
     * @When PayPlug notified that the payment is expired
     */
    public function PayPlugExpiredThePayment(): void
    {
        $this->payPlugApiMocker->mockApiExpiredPayment(function () {
            $this->paymentPage->notify([]);
        });
    }

    /**
     * @When I try to pay again PayPlug payment
     */
    public function iTryToPayAgainPayPlugPayment(): void
    {
        $this->payPlugApiMocker->mockMultipleApiCancelledPayment(function () {
            $this->payPlugApiMocker->mockPayPlugApiGetGatewayFactoryName(function () {
                $this->payPlugApiMocker->mockApiCreatePayment(function () {
                    $this->orderDetails->pay();
                });
            });
        });
    }

    /**
     * @Then /^the (latest order) state should be "([^"]+)"$/
     */
    public function theLatestOrderHasState(OrderInterface $order, string $state)
    {
        Assert::eq($order->getState(), $state);
    }

    /**
     * @Then /^the (latest order) shipping state should be "([^"]+)"$/
     */
    public function theLatestOrderHasShippingState(OrderInterface $order, string $state)
    {
        Assert::eq($order->getShippingState(), $state);
    }

    /**
     * @Given Oney is enabled
     */
    public function oneyIsEnabled(): void
    {
        $this->payPlugApiMocker->enableOney();
    }

    /**
     * @Given Oney is disabled
     */
    public function oneyIsDisabled(): void
    {
        $this->payPlugApiMocker->disableOney();
    }
}
