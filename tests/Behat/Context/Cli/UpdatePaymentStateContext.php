<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Cli;

use Behat\Behat\Context\Context;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\CoreBundle\Command\SetupCommand;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker\PayPlugApiMocker;
use Webmozart\Assert\Assert;

final class UpdatePaymentStateContext implements Context
{
    /** @var KernelInterface */
    private $kernel;

    /** @var Application */
    private $application;

    /** @var CommandTester */
    private $tester;

    /** @var SetupCommand */
    private $command;

    /** @var PayPlugApiMocker */
    private $payPlugApiMocker;

    /** @var PaymentFactoryInterface */
    private $paymentFactory;

    /** @var PaymentRepositoryInterface */
    private $paymentRepository;

    /** @var SharedStorageInterface */
    private $sharedStorage;

    public function __construct(
        KernelInterface $kernel,
        PayPlugApiMocker $payPlugApiMocker,
        PaymentFactoryInterface $paymentFactory,
        PaymentRepositoryInterface $paymentRepository,
        SharedStorageInterface $sharedStorage,
    ) {
        $this->kernel = $kernel;
        $this->payPlugApiMocker = $payPlugApiMocker;
        $this->paymentFactory = $paymentFactory;
        $this->paymentRepository = $paymentRepository;
        $this->sharedStorage = $sharedStorage;
    }

    /**
     * @When I run update payment state command
     */
    public function iRunUpdatePaymentStateCommand(): void
    {
        $this->application = new Application($this->kernel);
        $this->application->add(new SetupCommand());

        $this->command = $this->application->find('payplug:update-payment-state');
        $this->tester = new CommandTester($this->command);

        $this->payPlugApiMocker->mockApiStatePayment(function (): void {
            $this->tester->execute(['command' => 'payplug:update-payment-state']);
        });
    }

    /**
     * @When a payplug payment is created
     */
    public function aPayplugPaymentIsCreated()
    {
        $payment = $this->paymentFactory->createWithAmountAndCurrencyCode(1111, 'EUR');
        $payment->setMethod($this->sharedStorage->get('payment_method'));
        $payment->setDetails(['payment_id' => '123456']);
        $payment->setState(PaymentInterface::STATE_NEW);

        /** @var OrderInterface $order */
        $order = $this->sharedStorage->get('order');
        $order->addPayment($payment);

        $this->paymentRepository->add($payment);
    }

    /**
     * @Then I should see :output in output
     */
    public function iShouldSeeInOutput(string $output): void
    {
        Assert::contains($this->tester->getDisplay(), $output);
    }
}
