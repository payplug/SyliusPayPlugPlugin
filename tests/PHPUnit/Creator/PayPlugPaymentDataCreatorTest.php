<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Creator;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\PayplugFeatureChecker;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class PayPlugPaymentDataCreatorTest extends TestCase
{
    private CanSaveCardCheckerInterface&MockObject $canSaveCardChecker;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private RequestStack&MockObject $requestStack;

    private PayplugFeatureChecker&MockObject $payplugFeatureChecker;

    private PayPlugPaymentDataCreator $creator;

    protected function setUp(): void
    {
        $this->canSaveCardChecker = $this->createMock(CanSaveCardCheckerInterface::class);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->payplugFeatureChecker = $this->createMock(PayplugFeatureChecker::class);

        $this->creator = new PayPlugPaymentDataCreator(
            $this->canSaveCardChecker,
            $this->payplugCardRepository,
            $this->requestStack,
            $this->payplugFeatureChecker,
        );
    }

    // -------------------------------------------------------------------------
    // formatNumber() tests
    // -------------------------------------------------------------------------

    /**
     * Parses a valid French mobile number (06…) via libphonenumber.
     * Expects E.164 format and is_mobile=true.
     */
    public function testFormatNumber_validMobileNumberFR_returnsMobileFlag(): void
    {
        $result = $this->creator->formatNumber('0615151515', 'FR');

        self::assertSame('+33615151515', $result['phone']);
        self::assertTrue($result['is_mobile']);
    }

    /**
     * Parses a valid French landline number (01…) via libphonenumber.
     * Expects E.164 format and is_mobile=false.
     */
    public function testFormatNumber_validLandlineNumberFR_returnsNonMobileFlag(): void
    {
        $result = $this->creator->formatNumber('0123456789', 'FR');

        self::assertStringStartsWith('+33', $result['phone']);
        self::assertFalse($result['is_mobile']);
    }

    /**
     * Passes a too-short number that fails libphonenumber validation (not parseable as valid).
     * Expects both phone and is_mobile to be null.
     */
    public function testFormatNumber_invalidNumber_returnsNullValues(): void
    {
        $result = $this->creator->formatNumber('123', 'FR');

        self::assertNull($result['phone']);
        self::assertNull($result['is_mobile']);
    }

    /**
     * Passes a non-numeric string that libphonenumber cannot parse at all.
     * Expects both phone and is_mobile to be null (exception caught internally).
     */
    public function testFormatNumber_unparseable_returnsNullValues(): void
    {
        // A string that libphonenumber cannot parse at all
        $result = $this->creator->formatNumber('not-a-phone', 'FR');

        self::assertNull($result['phone']);
        self::assertNull($result['is_mobile']);
    }

    /**
     * Parses a valid Belgian landline number with the BE region hint.
     * Verifies the country-code prefix (+32) is correctly applied in E.164 format.
     */
    public function testFormatNumber_validBelgianNumber_returnsFormattedE164(): void
    {
        $result = $this->creator->formatNumber('023456789', 'BE');

        self::assertSame('+3223456789', $result['phone']);
        self::assertFalse($result['is_mobile']);
    }

    // -------------------------------------------------------------------------
    // create() — basic fields
    // -------------------------------------------------------------------------

    /**
     * Calls create() with a minimal payment (no gateway, no phone).
     * Verifies amount, currency, customer_id and order_number are all set in the output.
     */
    public function testCreate_basicPayment_populatesAmountCurrencyMetadata(): void
    {
        $payment = $this->buildMinimalPayment(1500, 'EUR', 42, 'ORDER-001');

        $details = $this->creator->create($payment);

        self::assertSame(1500, $details['amount']);
        self::assertSame('EUR', $details['currency']);
        self::assertSame(42, $details['metadata']['customer_id']);
        self::assertSame('ORDER-001', $details['metadata']['order_number']);
    }

    /**
     * Calls create() with an explicit $context array passed as second argument.
     * Verifies the array is forwarded verbatim as payment_context in the output.
     */
    public function testCreate_withContext_addsPaymentContextField(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1');
        $context = ['some' => 'context'];

        $details = $this->creator->create($payment, $context);

        self::assertSame($context, $details['payment_context']);
    }

    /**
     * Calls create() without passing a $context argument (default null).
     * Verifies payment_context is absent from the output array.
     */
    public function testCreate_withoutContext_doesNotAddPaymentContextField(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1');

        $details = $this->creator->create($payment);

        self::assertArrayNotHasKey('payment_context', $details->getArrayCopy());
    }

    // -------------------------------------------------------------------------
    // create() — billing address title (gender mapping)
    // -------------------------------------------------------------------------

    /**
     * Builds a payment with a customer whose gender is 'm'.
     * Verifies formatTitle() maps it to the PayPlug salutation 'mr'.
     */
    public function testCreate_maleCustomer_billingTitleIsMr(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm');

        $details = $this->creator->create($payment);

        self::assertSame('mr', $details['billing']['title']);
    }

    /**
     * Builds a payment with a customer whose gender is 'f'.
     * Verifies formatTitle() maps it to the PayPlug salutation 'mrs'.
     */
    public function testCreate_femaleCustomer_billingTitleIsMrs(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'f');

        $details = $this->creator->create($payment);

        self::assertSame('mrs', $details['billing']['title']);
    }

    /**
     * Builds a payment with a customer whose gender is an unrecognised value ('u').
     * Verifies formatTitle() returns null (no match in the gender→salutation map).
     */
    public function testCreate_unknownGenderCustomer_billingTitleIsNull(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'u');

        $details = $this->creator->create($payment);

        self::assertNull($details['billing']['title']);
    }

    // -------------------------------------------------------------------------
    // create() — locale / language code
    // -------------------------------------------------------------------------

    /**
     * Sets the order locale to 'fr_FR' (full Sylius locale with region).
     * Verifies formatLanguageCode() truncates it to just 'fr' in billing.language.
     */
    public function testCreate_frFrLocale_billingLanguageIsFr(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR');

        $details = $this->creator->create($payment);

        self::assertSame('fr', $details['billing']['language']);
    }

    /**
     * Sets the order locale to 'en_US'.
     * Verifies formatLanguageCode() truncates it to 'en'.
     */
    public function testCreate_enUsLocale_billingLanguageIsEn(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'en_US');

        $details = $this->creator->create($payment);

        self::assertSame('en', $details['billing']['language']);
    }

    // -------------------------------------------------------------------------
    // create() — delivery type (billing vs new)
    // -------------------------------------------------------------------------

    /**
     * Uses identical address IDs for billing and shipping.
     * Verifies the delivery_type field is set to 'BILLING' (ship-to-billing shortcut).
     */
    public function testCreate_sameShippingAndBillingId_deliveryTypeIsBilling(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR', sameAddress: true);

        $details = $this->creator->create($payment);

        self::assertSame('BILLING', $details['shipping']['delivery_type']);
    }

    /**
     * Uses distinct address IDs for billing and shipping.
     * Verifies the delivery_type field is set to 'NEW'.
     */
    public function testCreate_differentShippingAndBillingId_deliveryTypeIsNew(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR', sameAddress: false);

        $details = $this->creator->create($payment);

        self::assertSame('NEW', $details['shipping']['delivery_type']);
    }

    // -------------------------------------------------------------------------
    // create() — PayPlug gateway one-click / deferred capture
    // -------------------------------------------------------------------------

    /**
     * Uses the PayPlug gateway with CanSaveCardChecker returning false and deferred capture disabled.
     * Verifies allow_save_card is false (one-click feature off).
     */
    public function testCreate_payplugGateway_setsAllowSaveCardFalseByDefault(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(false);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(false);

        $details = $this->creator->create($payment);

        self::assertFalse($details['allow_save_card']);
    }

    /**
     * One-click is allowed but the session holds no 'payplug_payment_method' key (new customer).
     * Verifies allow_save_card is true so the card-saving checkbox appears at checkout.
     */
    public function testCreate_payplugGatewayWithOneClickAllowed_sessionNull_setsAllowSaveCardTrue(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(true);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(false);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('payplug_payment_method')->willReturn(null);
        $this->requestStack->method('getSession')->willReturn($session);

        $details = $this->creator->create($payment);

        self::assertTrue($details['allow_save_card']);
    }

    /**
     * One-click is allowed and the session holds 'other' (not a numeric card ID).
     * Verifies allow_save_card is still true and no payment_method/initiator fields are injected.
     */
    public function testCreate_payplugGatewayWithOneClickAllowed_sessionOther_setsAllowSaveCardTrue(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(true);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(false);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('payplug_payment_method')->willReturn('other');
        $this->requestStack->method('getSession')->willReturn($session);

        $details = $this->creator->create($payment);

        self::assertTrue($details['allow_save_card']);
    }

    /**
     * Session holds a numeric card ID that resolves to a saved Card entity in the repository.
     * Verifies the card's external ID is set as payment_method and initiator is set to 'PAYER'.
     */
    public function testCreate_payplugGatewayWithValidCardId_setsPaymentMethodFromCard(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(true);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(false);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('payplug_payment_method')->willReturn('42');
        $this->requestStack->method('getSession')->willReturn($session);

        $card = $this->createMock(Card::class);
        $card->method('getExternalId')->willReturn('card_ext_id_xyz');
        $this->payplugCardRepository->method('find')->with('42')->willReturn($card);

        $details = $this->creator->create($payment);

        self::assertSame('card_ext_id_xyz', $details['payment_method']);
        self::assertSame('PAYER', $details['initiator']);
    }

    /**
     * Session holds a numeric card ID that returns null from the repository (card not found).
     * Verifies payment_method and initiator are absent from the output (one-click skipped).
     */
    public function testCreate_payplugGatewayWithInvalidCardId_skipsPaymentMethod(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(true);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(false);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('payplug_payment_method')->willReturn('99');
        $this->requestStack->method('getSession')->willReturn($session);

        $this->payplugCardRepository->method('find')->with('99')->willReturn(null);

        $details = $this->creator->create($payment);

        self::assertArrayNotHasKey('payment_method', $details->getArrayCopy());
        self::assertArrayNotHasKey('initiator', $details->getArrayCopy());
    }

    /**
     * Enables deferred capture via PayplugFeatureChecker.
     * Verifies amount is replaced by authorized_amount and the amount key is absent from the payload.
     */
    public function testCreate_payplugGatewayWithDeferredCapture_convertsAmountToAuthorizedAmount(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(PayPlugGatewayFactory::FACTORY_NAME);
        $this->canSaveCardChecker->method('isAllowed')->willReturn(false);
        $this->payplugFeatureChecker->method('isDeferredCaptureEnabled')->willReturn(true);

        $details = $this->creator->create($payment);

        self::assertArrayNotHasKey('amount', $details->getArrayCopy());
        self::assertSame(1000, $details['authorized_amount']);
    }

    // -------------------------------------------------------------------------
    // create() — Oney gateway
    // -------------------------------------------------------------------------

    /**
     * Uses the Oney gateway factory with a session value selecting 'oney_x3_with_fees'.
     * Verifies payment_method, auto_capture, authorized_amount and payment_context are all set.
     */
    public function testCreate_oneyGateway_setsOneySpecificFields(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(OneyGatewayFactory::FACTORY_NAME);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')
            ->with('oney_payment_method', 'oney_x3_with_fees')
            ->willReturn('oney_x3_with_fees');
        $this->requestStack->method('getSession')->willReturn($session);

        $details = $this->creator->create($payment);

        self::assertSame('oney_x3_with_fees', $details['payment_method']);
        self::assertTrue($details['auto_capture']);
        self::assertArrayNotHasKey('amount', $details->getArrayCopy());
        self::assertSame(1000, $details['authorized_amount']);
        self::assertArrayHasKey('payment_context', $details->getArrayCopy());
    }

    // -------------------------------------------------------------------------
    // create() — Bancontact gateway (PPRO payment_method field)
    // -------------------------------------------------------------------------

    /**
     * Uses the Bancontact gateway factory (a PPRO method routed through PayPlug).
     * Verifies the payment_method field is set to the literal string 'bancontact'.
     */
    public function testCreate_bancontactGateway_setsBancontactPaymentMethod(): void
    {
        $payment = $this->buildMinimalPaymentWithGateway(BancontactGatewayFactory::FACTORY_NAME);

        $details = $this->creator->create($payment);

        self::assertSame('bancontact', $details['payment_method']);
    }

    // -------------------------------------------------------------------------
    // create() — phone number in billing / shipping address
    // -------------------------------------------------------------------------

    /**
     * Sets a French mobile number on the billing address.
     * Verifies mobile_phone_number is populated and landline_phone_number is null.
     */
    public function testCreate_withMobilePhoneInBillingAddress_populatesMobilePhone(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR', false, '0615151515');

        $details = $this->creator->create($payment);

        self::assertSame('+33615151515', $details['billing']['mobile_phone_number']);
        self::assertNull($details['billing']['landline_phone_number']);
    }

    /**
     * Sets a French landline number on the billing address.
     * Verifies landline_phone_number is populated and mobile_phone_number is null.
     */
    public function testCreate_withLandlinePhoneInBillingAddress_populatesLandlinePhone(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR', false, '0123456789');

        $details = $this->creator->create($payment);

        self::assertNull($details['billing']['mobile_phone_number']);
        self::assertStringStartsWith('+33', $details['billing']['landline_phone_number']);
    }

    /**
     * Sets getPhoneNumber() to null on the billing address.
     * Verifies both mobile_phone_number and landline_phone_number are null in the output.
     */
    public function testCreate_withNullPhone_doesNotPopulatePhoneFields(): void
    {
        $payment = $this->buildMinimalPayment(1000, 'EUR', 1, 'ORD-1', 'm', 'fr_FR', false, null);

        $details = $this->creator->create($payment);

        self::assertNull($details['billing']['mobile_phone_number']);
        self::assertNull($details['billing']['landline_phone_number']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMinimalPayment(
        int $amount,
        string $currency,
        int $customerId,
        string $orderNumber,
        string $gender = 'm',
        string $locale = 'fr_FR',
        bool $sameAddress = false,
        ?string $phone = null,
    ): PaymentInterface {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getGender')->willReturn($gender);
        $customer->method('getEmail')->willReturn('test@example.com');

        $billingAddress = $this->buildAddress(1, 'FR', $phone);
        $shippingAddress = $this->buildAddress($sameAddress ? 1 : 2, 'FR', $phone);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn($orderNumber);
        $order->method('getLocaleCode')->willReturn($locale);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('getItems')->willReturn(new ArrayCollection([]));
        $order->method('getShipments')->willReturn(new ArrayCollection([]));

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn($currency);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn(null);

        return $payment;
    }

    private function buildMinimalPaymentWithGateway(string $factoryName): PaymentInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $customer->method('getGender')->willReturn('m');
        $customer->method('getEmail')->willReturn('test@example.com');

        $billingAddress = $this->buildAddress(1, 'FR', null);
        $shippingAddress = $this->buildAddress(2, 'FR', null);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('PROD-001');

        $unit = $this->createMock(OrderItemUnitInterface::class);
        $unit->method('getId')->willReturn(10);

        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getUnits')->willReturn(new ArrayCollection([$unit]));
        $orderItem->method('getVariant')->willReturn($variant);
        $orderItem->method('getProductName')->willReturn('Product A');
        $orderItem->method('getVariantName')->willReturn('Size M');
        $orderItem->method('getTotal')->willReturn(1000);
        $orderItem->method('getUnitPrice')->willReturn(1000);
        $orderItem->method('getQuantity')->willReturn(1);

        $shippingMethod = $this->createMock(ShippingMethodInterface::class);
        $shippingMethod->method('getName')->willReturn('DHL');

        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getMethod')->willReturn($shippingMethod);

        $itemUnitsCollection = $this->createMock(\Doctrine\Common\Collections\Collection::class);
        $itemUnitsCollection->method('count')->willReturn(1);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('ORD-001');
        $order->method('getLocaleCode')->willReturn('fr_FR');
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('getItems')->willReturn(new ArrayCollection([$orderItem]));
        $order->method('getShipments')->willReturn(new ArrayCollection([$shipment]));
        $order->method('getItemUnits')->willReturn($itemUnitsCollection);

        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getMethod')->willReturn($paymentMethod);

        return $payment;
    }

    private function buildAddress(int $id, string $countryCode, ?string $phone): AddressInterface
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getId')->willReturn($id);
        $address->method('getCountryCode')->willReturn($countryCode);
        $address->method('getPhoneNumber')->willReturn($phone);
        $address->method('getFirstName')->willReturn('John');
        $address->method('getLastName')->willReturn('Doe');
        $address->method('getCompany')->willReturn(null);
        $address->method('getStreet')->willReturn('1 Rue de la Paix');
        $address->method('getPostcode')->willReturn('75001');
        $address->method('getCity')->willReturn('Paris');
        $address->method('getProvinceName')->willReturn(null);

        return $address;
    }
}
