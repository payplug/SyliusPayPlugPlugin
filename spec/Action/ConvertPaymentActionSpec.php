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
        $customer->getGender()->willReturn('M');

        $address->getId()->willReturn(1);
        $address->getFirstName()->willReturn('John');
        $address->getLastName()->willReturn('Doe');
        $address->getPhoneNumber()->willReturn('0606060606');
        $address->getCompany()->willReturn('Bob');
        $address->getPostcode()->willReturn('97980');
        $address->getStreet()->willReturn('test');
        $address->getCity()->willReturn('City');
        $address->getProvinceName()->willReturn('State');
        $address->getCountryCode()->willReturn('US');

        $order->getCustomer()->willReturn($customer);
        $order->getBillingAddress()->willReturn($address);
        $order->getShippingAddress()->willReturn($address);
        $order->getNumber()->willReturn('000000001');
        $order->getLocaleCode()->willReturn('en_US');

        $payment->getOrder()->willReturn($order);
        $payment->getDetails()->willReturn([]);
        $payment->getAmount()->willReturn(100);
        $payment->getCurrencyCode()->willReturn('EUR');

        $request->getSource()->willReturn($payment);
        $request->getTo()->willReturn('array');

        $request->setResult([
            'amount' => 100,
            'currency' => 'EUR',
            'metadata' => [
                'customer_id' => 1,
                'order_number' => '000000001',
            ],
            'billing' => [
              'title' => null,
              'first_name' => 'John',
              'last_name' => 'Doe',
              'company_name' => 'Bob',
              'email' => 'test@test.pl',
              'mobile_phone_number' => null,
              'landline_phone_number' => null,
              'address1' => 'test',
              'address2' => null,
              'postcode' => '97980',
              'city' => 'City',
              'state' => 'State',
              'country' => 'US',
              'language' => 'en',
            ],
            'shipping' => [
              'title' => null,
              'first_name' => 'John',
              'last_name' => 'Doe',
              'company_name' => 'Bob',
              'email' => 'test@test.pl',
              'mobile_phone_number' => null,
              'landline_phone_number' => null,
              'address1' => 'test',
              'address2' => null,
              'postcode' => '97980',
              'city' => 'City',
              'state' => 'State',
              'country' => 'US',
              'language' => 'en',
              'delivery_type' => 'BILLING',
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
