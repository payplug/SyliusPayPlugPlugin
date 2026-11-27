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
        ?string $countryCode = 'FR',
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

    /**
     * @dataProvider nonStrictPhoneProvider
     */
    public function testCreateBilling_withoutRegionMatch_sendsEveryValidNumberInItsOwnCountry(
        string $rawPhone,
        string $countryCode,
        ?string $expectedPhone,
        ?string $expectedMobilePhone,
    ): void {
        $order = $this->orderWithAddresses($this->address($rawPhone, $countryCode), null);

        $billing = $this->factory->createBilling($order, false);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertSame($expectedPhone, $billing->contact->phone);
        self::assertSame($expectedMobilePhone, $billing->contact->mobilePhone);
    }

    /**
     * @return iterable<string, array{string, string, string|null, string|null}>
     */
    public static function nonStrictPhoneProvider(): iterable
    {
        yield 'FR mobile on a DE address' => ['+33612345678', 'DE', null, '+33612345678'];
        yield 'FR landline on a DE address' => ['+33142345678', 'DE', '+33142345678', null];
        yield 'FR mobile with 00 prefix on a BE address' => ['0033609860479', 'BE', null, '+33609860479'];
        yield 'BE mobile with 00 prefix on a FR address' => ['0032 470 12 34 56', 'FR', null, '+32470123456'];
        yield 'metropolitan mobile on a MQ address' => ['07 81 23 45 67', 'MQ', null, '+33781234567'];
        yield 'metropolitan mobile on a lowercase mq address' => ['07 81 23 45 67', 'mq', null, '+33781234567'];
        yield 'MQ mobile stays in MQ' => ['0696 12 34 56', 'MQ', null, '+596696123456'];
        yield 'YT landline also valid in FR stays in YT' => ['02 69 61 23 45', 'YT', '+262269612345', null];
        yield 'shared +590 mobile on a BL address' => ['0690 12 34 56', 'BL', null, '+590690123456'];
        yield 'shared +590 mobile on a MF address' => ['0690123456', 'MF', null, '+590690123456'];
        yield 'shared +590 landline on a MF address' => ['0590871234', 'MF', '+590590871234', null];
        yield 'national number read in DE, not retried as FR' => ['0612345678', 'DE', '+49612345678', null];
        yield 'national number invalid in BE, not retried as FR' => ['0612345678', 'BE', null, null];
        yield 'invalid number on a MQ address' => ['0612 34', 'MQ', null, null];
    }

    /**
     * @dataProvider strictPhoneProvider
     */
    public function testCreateBilling_withRegionMatch_rejectsForeignNumbersButRetriesOverseasTerritories(
        string $rawPhone,
        string $countryCode,
        ?string $expectedMobilePhone,
    ): void {
        $order = $this->orderWithAddresses($this->address($rawPhone, $countryCode), null);

        $billing = $this->factory->createBilling($order, true);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertSame($expectedMobilePhone, $billing->contact->mobilePhone);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function strictPhoneProvider(): iterable
    {
        yield 'FR mobile on a DE address' => ['+33612345678', 'DE', null];
        yield 'FR mobile with 00 prefix on a BE address' => ['0033609860479', 'BE', null];
        yield 'BE mobile with 00 prefix on a FR address' => ['0032 470 12 34 56', 'FR', null];
        yield 'metropolitan mobile on a MQ address' => ['07 81 23 45 67', 'MQ', '+33781234567'];
        yield 'MQ mobile stays in MQ' => ['0696 12 34 56', 'MQ', '+596696123456'];
        yield 'shared +590 mobile on a BL address' => ['0690 12 34 56', 'BL', '+590690123456'];
        yield 'shared +590 mobile on a MF address' => ['0690123456', 'MF', '+590690123456'];
        yield 'FR mobile on a FR address' => ['0612345678', 'FR', '+33612345678'];
        yield 'invalid number on a MQ address' => ['0612 34', 'MQ', null];
    }

    public function testCreateBilling_withoutTheRegionMatchArgument_rejectsAForeignNumber(): void
    {
        $order = $this->orderWithAddresses($this->address('+33612345678', 'DE'), null);

        $billing = $this->factory->createBilling($order);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertNull($billing->contact->mobilePhone);
    }

    /**
     * @dataProvider frenchNumberingTerritoryProvider
     */
    public function testCreateBilling_withAMetropolitanMobileOnAnOverseasTerritory_sendsItAsFrench(
        string $countryCode,
        bool $requireRegionMatch,
    ): void {
        $order = $this->orderWithAddresses($this->address('0612345678', $countryCode), null);

        $billing = $this->factory->createBilling($order, $requireRegionMatch);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertSame('+33612345678', $billing->contact->mobilePhone);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function frenchNumberingTerritoryProvider(): iterable
    {
        foreach (['GP', 'MQ', 'RE', 'GF', 'YT', 'PM', 'BL', 'MF', 'NC', 'PF', 'WF'] as $countryCode) {
            yield $countryCode . ' strict' => [$countryCode, true];
            yield $countryCode . ' non-strict' => [$countryCode, false];
        }
    }

    public function testCreateBilling_withNoCountryCode_leavesBothPhoneFieldsNull(): void
    {
        $order = $this->orderWithAddresses($this->address('+33612345678', null), null);

        $billing = $this->factory->createBilling($order, false);

        self::assertNotNull($billing);
        self::assertNotNull($billing->contact);
        self::assertNull($billing->contact->phone);
        self::assertNull($billing->contact->mobilePhone);
    }

    public function testCreateShipping_withoutRegionMatch_sendsAForeignNumber(): void
    {
        $order = $this->orderWithAddresses(null, $this->address('+33612345678', 'DE'));

        $shipping = $this->factory->createShipping($order, false);

        self::assertNotNull($shipping);
        self::assertNotNull($shipping->contact);
        self::assertSame('+33612345678', $shipping->contact->mobilePhone);
    }

    public function testCreateShipping_withRegionMatch_rejectsAForeignNumber(): void
    {
        $order = $this->orderWithAddresses(null, $this->address('+33612345678', 'DE'));

        $shipping = $this->factory->createShipping($order, true);

        self::assertNotNull($shipping);
        self::assertNotNull($shipping->contact);
        self::assertNull($shipping->contact->phone);
        self::assertNull($shipping->contact->mobilePhone);
    }

    public function testCreateShipping_withoutTheRegionMatchArgument_rejectsAForeignNumber(): void
    {
        $order = $this->orderWithAddresses(null, $this->address('+33612345678', 'DE'));

        $shipping = $this->factory->createShipping($order);

        self::assertNotNull($shipping);
        self::assertNotNull($shipping->contact);
        self::assertNull($shipping->contact->phone);
        self::assertNull($shipping->contact->mobilePhone);
    }
}
