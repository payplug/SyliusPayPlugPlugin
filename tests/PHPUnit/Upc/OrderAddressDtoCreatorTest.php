<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoCreator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class OrderAddressDtoCreatorTest extends TestCase
{
    private OrderAddressDtoCreator $factory;

    protected function setUp(): void
    {
        $this->factory = new OrderAddressDtoCreator();
    }

    private function address(
        ?string $phoneNumber = null,
        string $countryCode = 'FR',
        string $provinceCode = '75',
    ): AddressInterface&MockObject {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getFirstName')->willReturn('Jane');
        $address->method('getLastName')->willReturn('Doe');
        $address->method('getStreet')->willReturn('10 Rue de Rivoli');
        $address->method('getCity')->willReturn('Paris');
        $address->method('getCountryCode')->willReturn($countryCode);
        $address->method('getProvinceCode')->willReturn($provinceCode);
        $address->method('getPostcode')->willReturn('75001');
        $address->method('getCompany')->willReturn('Acme Corp');
        $address->method('getPhoneNumber')->willReturn($phoneNumber);

        return $address;
    }

    private function orderWithAddresses(
        ?AddressInterface $billing,
        ?AddressInterface $shipping,
        ?string $customerEmail = 'jane@example.com',
        string $customerGender = '',
    ): OrderInterface&MockObject {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($customerEmail);
        $customer->method('getGender')->willReturn($customerGender);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getShippingAddress')->willReturn($shipping);

        return $order;
    }

    public function testCreateBilling_withNoBillingAddress_returnsNull(): void
    {
        $order = $this->orderWithAddresses(null, null);

        self::assertNull($this->factory->createBilling($order));
    }

    public function testCreateShipping_withNoShippingAddress_returnsNull(): void
    {
        $order = $this->orderWithAddresses(null, null);

        self::assertNull($this->factory->createShipping($order));
    }

    public function testCreateBilling_withAFullAddressAndMobilePhone_mapsEveryField(): void
    {
        $order = $this->orderWithAddresses($this->address('+33612345678'), null, customerGender: 'f');

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertSame('Jane', $billing->contact->firstName);
        self::assertSame('Doe', $billing->contact->lastName);
        self::assertSame('MRS', $billing->title);
        self::assertNull($billing->contact->phone);
        self::assertSame('+33612345678', $billing->contact->mobilePhone);
        self::assertNotNull($billing->address);
        self::assertSame('10 Rue de Rivoli', $billing->address->line);
        self::assertSame('Paris', $billing->address->city);
        self::assertSame('FR', $billing->address->country);
        self::assertSame('75', $billing->address->state);
        self::assertSame('75001', $billing->address->zipCode);
    }

    public function testCreateBilling_withAProvinceCodeLongerThanThreeChars_omitsState(): void
    {
        $address = $this->address(provinceCode: 'US-CA');
        $order = $this->orderWithAddresses($address, null);

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->address);
        self::assertNull($billing->address->state);
    }

    public function testCreateBilling_withALandlinePhone_setsPhoneNotMobilePhone(): void
    {
        $order = $this->orderWithAddresses($this->address('+33142345678'), null);

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertSame('+33142345678', $billing->contact->phone);
        self::assertNull($billing->contact->mobilePhone);
    }

    public function testCreateBilling_withNoPhoneNumber_leavesBothPhoneFieldsNull(): void
    {
        $order = $this->orderWithAddresses($this->address(null), null);

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertNull($billing->contact->mobilePhone);
    }

    public function testCreateBilling_withAnUnparseablePhoneNumber_leavesBothPhoneFieldsNullInsteadOfThrowing(): void
    {
        $order = $this->orderWithAddresses($this->address('not-a-phone-number'), null);

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertNull($billing->contact->mobilePhone);
    }

    public function testCreateBilling_withMaleGender_mapsTitleToMr(): void
    {
        $order = $this->orderWithAddresses($this->address(), null, customerGender: 'm');

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertSame('MR', $billing->title);
    }

    public function testCreateBilling_withNoGender_leavesTitleNull(): void
    {
        $order = $this->orderWithAddresses($this->address(), null, customerGender: '');

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNull($billing->title);
    }

    public function testCreateShipping_withAFullAddress_mapsEveryFieldIncludingCustomerEmailAndCompany(): void
    {
        $order = $this->orderWithAddresses(null, $this->address('+33612345678'), customerEmail: 'jane@example.com');

        $shipping = $this->factory->createShipping($order);

        self::assertNotNull($shipping);
        self::assertNotNull($shipping->contact);
        self::assertSame('Jane', $shipping->contact->firstName);
        self::assertSame('Doe', $shipping->contact->lastName);
        self::assertSame('jane@example.com', $shipping->email);
        self::assertSame('Acme Corp', $shipping->companyName);
        self::assertNull($shipping->contact->phone);
        self::assertSame('+33612345678', $shipping->contact->mobilePhone);
        self::assertNotNull($shipping->address);
        self::assertSame('75001', $shipping->address->zipCode);
    }

    public function testCreateBillingAndCreateShipping_areIndependentOfEachOther(): void
    {
        $order = $this->orderWithAddresses($this->address('+33612345678'), null);

        self::assertNotNull($this->factory->createBilling($order));
        self::assertNull($this->factory->createShipping($order));
    }
}
