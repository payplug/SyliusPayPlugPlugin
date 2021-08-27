<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\ConvertPaymentAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\Convert;
use PhpSpec\ObjectBehavior;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ConvertPaymentActionSpec extends ObjectBehavior
{
    function let(
        SessionInterface $session,
        CanSaveCardCheckerInterface $canSaveCardChecker,
        RepositoryInterface $payplugCardRepository
    ): void {
        $this->beConstructedWith($session, $canSaveCardChecker, $payplugCardRepository);
    }

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
        OrderInterface $order,
        CustomerInterface $customer,
        AddressInterface $address
    ): void {
        $this->setApi(new PayPlugApiClient('test', PayPlugGatewayFactory::FACTORY_NAME));

        $customer = $this->getCustomer($customer);
        $address = $this->getAddress($address);
        $order = $this->getOrder($order, $customer, $address, $address);
        $payment = $this->getPayment($payment, $order);
        $payment->getMethod();

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

    function it_executes_with_different_address(
        Convert $request,
        PaymentInterface $payment,
        OrderInterface $order,
        CustomerInterface $customer,
        AddressInterface $address,
        AddressInterface $otherAddress
    ): void {
        $this->setApi(new PayPlugApiClient('test', PayPlugGatewayFactory::FACTORY_NAME));

        $customer = $this->getCustomer($customer);
        $address = $this->getAddress($address);
        $otherAddress = $this->getOtherAddress($otherAddress);
        $order = $this->getOrder($order, $customer, $address, $otherAddress);
        $payment = $this->getPayment($payment, $order);
        $payment->getMethod();

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
                'first_name' => 'Jean',
                'last_name' => 'Bon',
                'company_name' => 'Paris',
                'email' => 'test@test.pl',
                'mobile_phone_number' => null,
                'landline_phone_number' => null,
                'address1' => 'test',
                'address2' => null,
                'postcode' => '97980',
                'city' => 'Paris',
                'state' => 'Paris',
                'country' => 'US',
                'language' => 'en',
                'delivery_type' => 'NEW',
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

    private function getCustomer($customer)
    {
        $customer->getEmail()->willReturn('test@test.pl');
        $customer->getId()->willReturn(1);
        $customer->getGender()->willReturn('M');

        return $customer;
    }

    private function getAddress(AddressInterface $address)
    {
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

        return $address;
    }

    private function getOtherAddress(AddressInterface $address)
    {
        $address->getId()->willReturn(2);
        $address->getFirstName()->willReturn('Jean');
        $address->getLastName()->willReturn('Bon');
        $address->getPhoneNumber()->willReturn('0606060606');
        $address->getCompany()->willReturn('Paris');
        $address->getPostcode()->willReturn('97980');
        $address->getStreet()->willReturn('test');
        $address->getCity()->willReturn('Paris');
        $address->getProvinceName()->willReturn('Paris');
        $address->getCountryCode()->willReturn('US');

        return $address;
    }

    private function getOrder(
        OrderInterface $order,
        CustomerInterface $customer,
        AddressInterface $billingAddress,
        AddressInterface $shippingAddress
    ) {
        $order->getCustomer()->willReturn($customer);
        $order->getBillingAddress()->willReturn($billingAddress);
        $order->getShippingAddress()->willReturn($shippingAddress);
        $order->getNumber()->willReturn('000000001');
        $order->getLocaleCode()->willReturn('en_US');

        return $order;
    }

    private function getPayment(PaymentInterface $payment, OrderInterface $order)
    {
        $payment->getOrder()->willReturn($order);
        $payment->getDetails()->willReturn([]);
        $payment->getAmount()->willReturn(100);
        $payment->getCurrencyCode()->willReturn('EUR');

        return $payment;
    }
}
