<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\ConvertPaymentAction;
use Payum\Core\Action\ActionInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Convert;
use PhpSpec\ObjectBehavior;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentActionSpec extends ObjectBehavior
{
    function it_is_initializable(): void
    {
        $this->shouldHaveType(ConvertPaymentAction::class);
    }

    function it_implements_action_interface(): void
    {
        $this->shouldHaveType(ActionInterface::class);
    }

    function it_executes(
        Convert $request,
        PaymentInterface $payment,
        GatewayInterface $gateway,
        OrderInterface $order,
        CustomerInterface $customer,
        AddressInterface $address
    ): void {
        $this->setGateway($gateway);

        $customer->getEmail()->willReturn('test@test.pl');
        $customer->getId()->willReturn(1);
        $address->getFirstName()->willReturn('John');
        $address->getLastName()->willReturn('Doe');
        $address->getPostcode()->willReturn('97980');
        $address->getStreet()->willReturn('test');
        $address->getCity()->willReturn('City');
        $address->getCountryCode()->willReturn('US');
        $order->getCustomer()->willReturn($customer);
        $order->getBillingAddress()->willReturn($address);
        $order->getNumber()->willReturn('000000001');
        $payment->getOrder()->willReturn($order);
        $request->getSource()->willReturn($payment);
        $request->getTo()->willReturn('array');
        $payment->getDetails()->willReturn([]);
        $payment->getAmount()->willReturn(100);
        $payment->getCurrencyCode()->willReturn('EUR');

        $request->setResult([
            'amount' => 100,
            'currency' => 'EUR',
            'customer' => [
                'email' => 'test@test.pl',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => 'test',
                'city' => 'City',
                'country' => 'US',
                'postcode' => '97980',
            ],
            'metadata' => [
                'customer_id' => 1,
                'order_number' => '000000001',
            ],
        ])->shouldBeCalled();

        $this->execute($request);
    }

    function it_supports_only_convert_request_payment_source_and_array_to(
        Convert $request,
        PaymentInterface $payment
    ): void {
        $request->getSource()->willReturn($payment);
        $request->getTo()->willReturn('array');
        $this->supports($request)->shouldReturn(true);
    }
}
