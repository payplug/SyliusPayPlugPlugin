<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use App\Entity\Payment\Payment;
use Behat\Behat\Context\Context;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Action\NotifyAction;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
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

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var RefundHistoryRepositoryInterface */
    private $payplugRefundHistoryRepository;

    public function __construct(
        PayPlugApiMocker $payPlugApiMocker,
        ManagingOrdersContext $managingOrdersContext,
        RefundingContext $refundingContext,
        NotifyAction $notifyAction,
        NotificationCheckerInterface $notificationChecker,
        EntityManagerInterface $entityManager,
        RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
    ) {
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->managingOrdersContext = $managingOrdersContext;
        $this->refundingContext = $refundingContext;
        $this->notifyAction = $notifyAction;
        $this->notificationChecker = $notificationChecker;
        $this->entityManager = $entityManager;
        $this->payplugRefundHistoryRepository = $payplugRefundHistoryRepository;
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
        $this->payPlugApiMocker->mockApiRetrieveNotRefundablePayment(function () use (
            $order,
            $unitNumber,
            $productName,
            $paymentMethod
        ) {
            $this->payPlugApiMocker->mockApiRefundedWithAmountPayment(function () use (
                $order,
                $unitNumber,
                $productName,
                $paymentMethod
            ) {
                $this->refundingContext->decidedToRefundProduct($unitNumber, $productName, $order->getNumber(), $paymentMethod);
            });
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

    /**
     * @When /^For (this order) I decide to refund (\d)st "([^"]+)" product with "([^"]+)" payment after 48 hours$/
     */
    public function decideToRefundProductAfter48Hours(
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
            /** @var DateTime $createdAt */
            $createdAt = $order->getLastPayment()->getCreatedAt();
            $createdAt->modify('-48 hours');

            $payment = new Payment();
            $payment->setCreatedAt($createdAt);
            $payment->setAmount($order->getLastPayment()->getAmount());
            $payment->setDetails($order->getLastPayment()->getDetails());
            $payment->setCurrencyCode($order->getLastPayment()->getCurrencyCode());
            $payment->setMethod($order->getLastPayment()->getMethod());
            $payment->setOrder($order);
            $payment->setState($order->getLastPayment()->getState());

            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            $this->decideToRefundProduct($order, $unitNumber, $productName, $paymentMethod);
        });
    }

    /**
     * @Then /^I wait 48 hours after the last refund of (this order)$/
     */
    public function iWait48HoursAfterTheLastRefundOfThisOrder(OrderInterface $order)
    {
        $createdAt = new DateTime();
        $createdAt->modify('-48 hours');

        $payment = new Payment();
        $payment->setCreatedAt($createdAt);
        $payment->setAmount($order->getLastPayment()->getAmount());
        $payment->setDetails($order->getLastPayment()->getDetails());
        $payment->setCurrencyCode($order->getLastPayment()->getCurrencyCode());
        $payment->setMethod($order->getLastPayment()->getMethod());
        $payment->setOrder($order);
        $payment->setState($order->getLastPayment()->getState());

        $refundHistory = new RefundHistory();
        $refundHistory
            ->setCreatedAt($createdAt)
            ->setPayment($payment)
            ->setExternalId('09876543')
            ->setProcessed(true)
            ->setValue(1)
        ;

        $this->entityManager->persist($payment);
        $this->entityManager->persist($refundHistory);
        $this->entityManager->flush();
    }
}
