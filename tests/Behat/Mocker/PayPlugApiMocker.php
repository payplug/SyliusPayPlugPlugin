<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Behat\Service\Mocker\MockerInterface;

final class PayPlugApiMocker
{
    /** @var MockerInterface */
    private $mocker;

    public function __construct(MockerInterface $mocker)
    {
        $this->mocker = $mocker;
    }

    public function mockApiRefundedPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);

        $mock
            ->shouldReceive('initialise')
        ;

        $mock
            ->shouldReceive('refundPayment')
            ->andReturn(\Mockery::mock('refund', Refund::class))
        ;

        $action();

        $this->mocker->unmockAll();
    }

    public function mockApiCreatePayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);

        $mock
            ->shouldReceive('initialise')
        ;

        $payment = \Mockery::mock('payment', Payment::class);

        $payment->id = 1;
        $payment->hosted_payment = (object) [
            'payment_url' => 'test',
        ];

        $mock
            ->shouldReceive('createPayment')
            ->andReturn($payment)
        ;

        $action();

        $this->mocker->unmockAll();
    }

    public function mockApiSuccessfulPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);

        $mock
            ->shouldReceive('initialise')
        ;

        $payment = \Mockery::mock('payment', Payment::class);

        $payment->is_paid = true;

        $mock
            ->shouldReceive('treat')
            ->andReturn($payment)
        ;

        $action();

        $this->mocker->unmockAll();
    }

    public function mockApiFailedPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);

        $mock
            ->shouldReceive('initialise')
        ;

        $payment = \Mockery::mock('payment', Payment::class);

        $payment->is_paid = false;

        $mock
            ->shouldReceive('treat')
            ->andReturn($payment)
        ;

        $action();

        $this->mocker->unmockAll();
    }

    public function mockApiCancelledPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);

        $mock
            ->shouldReceive('initialise')
        ;

        $action();

        $this->mocker->unmockAll();
    }


    public function mockApiStatePayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock ->shouldReceive('initialise');

        $payment = \Mockery::mock('payment', Payment::class);
        $payment->state = 'failed';
        $payment->is_paid = false;
        $payment->failure = true;

        $mock
            ->shouldReceive('retrieve')//->withArgs(['paymentId' => '123456'])
            ->andReturn($payment)
        ;

        $action();

        $this->mocker->unmockAll();
    }

}
