<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Behat\Service\Mocker\MockerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class PayPlugApiMocker
{
    /** @var ContainerInterface */
    private $container;

    /** @var MockerInterface */
    private $mocker;

    public function __construct(MockerInterface $mocker, ContainerInterface $container)
    {
        $this->mocker = $mocker;
        $this->container = $container;
    }

    public function getPayPlugApiClient()
    {
        return new PayPlugApiClient($this->container, 'payplug_sylius_payplug_plugin.api_client.payplug');
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

    public function mockApiRefundedWithAmountPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->is_paid = true;
        $payment->created_at = 1598273578;
        $mock
            ->shouldReceive('retrieve')
            ->andReturn($payment)
        ;

        $refund = \Mockery::mock('refund', Refund::class);
        $refund->amount = 34000;
        $refund->currency = 'EUR';
        $refund->id = \bin2hex(\random_bytes(10));
        $refund->payment_id = 'pay_2PykkdCqJLzJ7nYM5gV4RZ';
        $refund->metadata = ['requested_by' => 'payplug'];
        $mock
            ->shouldReceive('refundPaymentWithAmount')
            ->andReturn($refund)
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
        $payment->id = 'pay_1';
        $payment->is_live = false;
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
            ->shouldReceive('createPayment')
        ;
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->is_paid = true;
        $payment->created_at = 1598273578;
        $mock
            ->shouldReceive('treat')
            ->andReturn($payment)
        ;
        $mock
            ->shouldReceive('retrieve')
            ->andReturn($payment)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiRetrievePayment(callable $action): void
    {
        $mock = $this->mocker->mockService(
            'payplug_sylius_payplug_plugin.api_client.oney',
            PayPlugApiClientInterface::class,
        );
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->refundable_until = (new \DateTime())->add(new \DateInterval('P2D'))->getTimestamp();
        $payment->refundable_after = (new \DateTime())->sub(new \DateInterval('P1D'))->getTimestamp();
        $mock
            ->shouldReceive('retrieve')
            ->andReturn($payment)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiRetrieveNotRefundablePayment(callable $action): void
    {
        $mock = $this->mocker->mockService(
            'payplug_sylius_payplug_plugin.api_client.oney',
            PayPlugApiClientInterface::class,
        );
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->refundable_until = (new \DateTime())->add(new \DateInterval('P2D'))->getTimestamp();
        $payment->refundable_after = (new \DateTime())->add(new \DateInterval('P1D'))->getTimestamp();
        $mock
            ->shouldReceive('retrieve')
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
        $payment->id = 'pay_1';
        $payment->is_paid = false;
        $mock
            ->shouldReceive('treat')
            ->andReturn($payment)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiExpiredPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->status = 'failure';
        $payment->is_paid = false;
        $failure = new \stdClass();
        $failure->code = 'timeout';
        $failure->message = 'The customer has not tried to pay and left the payment page.';
        $payment->failure = $failure;
        $mock
            ->shouldReceive('treat')
            ->andReturn($payment)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiCreatedPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;
        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->status = 'created';
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

        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->state = 'abort';
        $payment->is_paid = false;

        $mock
            ->shouldReceive('abortPayment')->once()
            ->withArgs(['pay_1'])
            ->andReturn($payment)
        ;

        $action();
        $this->mocker->unmockAll();
    }

    public function mockMultipleApiCancelledPayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;

        $payment = \Mockery::mock('payment', Payment::class);
        $payment->id = 'pay_1';
        $payment->state = 'abort';
        $payment->is_paid = false;

        $mock
            ->shouldReceive('abortPayment')
            ->withArgs(['pay_1'])
            ->andReturn($payment)
        ;

        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiStatePayment(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock->shouldReceive('initialise');
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

    public function refundPaymentWithAmount(callable $action): void
    {
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiRefundedFromPayPlugPortal(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;
        $refund = \Mockery::mock('refund', Refund::class);
        $refund->amount = 34000;
        $refund->currency = 'EUR';
        $refund->id = \bin2hex(\random_bytes(10));
        $refund->payment_id = 'pay_2PykkdCqJLzJ7nYM5gV4RZ';
        $refund->metadata = ['requested_by' => 'payplug'];
        $mock
            ->shouldReceive('treat')
            ->andReturn($refund)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockApiRefundPartiallyFromPayPlugPortal(callable $action, int $amount): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive('initialise')
        ;
        $refund = \Mockery::mock('refund', Refund::class);
        $refund->amount = $amount;
        $refund->currency = 'EUR';
        $refund->id = 'pay_' . \bin2hex(\random_bytes(10));
        $refund->payment_id = 'pay_2PykkdCqJLzJ7nYM5gV4RZ';
        $refund->metadata = ['requested_by' => 'payplug'];
        $mock
            ->shouldReceive('treat')
            ->andReturn($refund)
        ;
        $action();
        $this->mocker->unmockAll();
    }

    public function mockPayPlugApiGetGatewayFactoryName(callable $action): void
    {
        $mock = $this->mocker->mockService('payplug_sylius_payplug_plugin.api_client.payplug', PayPlugApiClientInterface::class);
        $mock
            ->shouldReceive([
                'getGatewayFactoryName' => PayPlugGatewayFactory::FACTORY_NAME,
            ])
        ;

        $action();
        $this->mocker->unmockAll();
    }

    public function enableOney(): void
    {
        $this->mocker->unmockAll();
        $mock = $this->mocker->mockService(
            'payplug_sylius_payplug_plugin.api_client.oney',
            PayPlugApiClientInterface::class,
        );
        $mock->shouldReceive([
            'getPermissions' => ['can_use_oney' => true],
            'getAccount' => [
                'country' => 'FR',
                'configuration' => [
                    'oney' => [
                        'min_amounts' => [
                            'EUR' => 10000,
                        ],
                        'max_amounts' => [
                            'EUR' => 300000,
                        ],
                        'allowed_countries' => [
                            'FR',
                            'US',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function disableOney(): void
    {
        $this->mocker->unmockAll();
        $mock = $this->mocker->mockService(
            'payplug_sylius_payplug_plugin.api_client.oney',
            PayPlugApiClientInterface::class,
        );
        $mock->shouldReceive([
            'getPermissions' => ['can_use_oney' => false],
        ]);
    }
}
