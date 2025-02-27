<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Creator;

use DateInterval;
use DateTime;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat as PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil as PhoneNumberUtil;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\PayplugFeatureChecker;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class PayPlugPaymentDataCreator
{
    private const DELIVERY_TYPE_BILLING = 'BILLING';
    private const DELIVERY_TYPE_NEW = 'NEW';
    private const PAYPLUG_CARD_ID_OTHER = 'other';

    private CanSaveCardCheckerInterface $canSaveCardChecker;
    private RepositoryInterface $payplugCardRepository;
    private RequestStack $requestStack;
    private PayplugFeatureChecker $payplugFeatureChecker;

    public function __construct(
        CanSaveCardCheckerInterface $canSaveCard,
        RepositoryInterface $payplugCardRepository,
        RequestStack $requestStack,
        PayplugFeatureChecker $payplugFeatureChecker,
    ) {
        $this->canSaveCardChecker = $canSaveCard;
        $this->payplugCardRepository = $payplugCardRepository;
        $this->requestStack = $requestStack;
        $this->payplugFeatureChecker = $payplugFeatureChecker;
    }

    public function create(
        PaymentInterface $payment,
        string $gatewayFactoryName,
        array $context = []
    ): ArrayObject {
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        /** @var CustomerInterface $customer */
        $customer = $order->getCustomer();

        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $details['amount'] = $payment->getAmount();
        $details['currency'] = $payment->getCurrencyCode();

        $details['metadata'] = [
            'customer_id' => $customer->getId(),
            'order_number' => $order->getNumber() ?? $order->getTokenValue() ?? $order->getId(),
        ];

        if ([] !== $context) {
            $details['payment_context'] = $context;
        }

        // DSP2 fields
        /** @var AddressInterface $shipping */
        $shipping = $order->getShippingAddress();
        /** @var AddressInterface $billing */
        $billing = $order->getBillingAddress();

        $deliveryType = $shipping->getId() === $billing->getId(
        ) ? self::DELIVERY_TYPE_BILLING : self::DELIVERY_TYPE_NEW;

        $this->addBillingInfo($billing, $customer, $order, $details);
        $this->addShippingInfo($shipping, $customer, $order, $deliveryType, $details);

        $paymentMethod = $payment->getMethod();

        if (PayPlugGatewayFactory::FACTORY_NAME === $gatewayFactoryName &&
            $paymentMethod instanceof PaymentMethodInterface) {
            $details['allow_save_card'] = false;
            $details = $this->alterPayPlugDetailsForOneClick($paymentMethod, $details);
            $details = $this->alterPayPlugDetailsForDeferredCapture($paymentMethod, $details);
        }

        if (OneyGatewayFactory::FACTORY_NAME === $gatewayFactoryName) {
            $details = $this->alterOneyDetails($details);
            $details->offsetSet('payment_context', $this->getCartContext($order));
        }

        $this->addPaymentMethodFieldToDetails($details, $gatewayFactoryName);

        return $details;
    }

    public function formatNumber(string $phoneNumber, ?string $isoCode): array
    {
        try {
            $phoneNumberUtil = PhoneNumberUtil::getInstance();
            $parsed = $phoneNumberUtil->parse($phoneNumber, $isoCode);

            if (!$phoneNumberUtil->isValidNumber($parsed)) {
                return [
                    'phone' => null,
                    'is_mobile' => null,
                ];
            }

            $formatted = $phoneNumberUtil->format($parsed, PhoneNumberFormat::E164);

            return [
                'phone' => $formatted,
                'is_mobile' => PhoneNumberType::MOBILE === $phoneNumberUtil->getNumberType($parsed),
            ];
        } catch (NumberParseException) {
            return [
                'phone' => null,
                'is_mobile' => null,
            ];
        }
    }

    private function formatTitle(CustomerInterface $customer): ?string
    {
        $gender = $customer->getGender();

        return 'm' === $gender ? 'mr' : ('f' === $gender ? 'mrs' : null);
    }

    private function formatLanguageCode(?string $languageCode): ?string
    {
        if (null === $languageCode) {
            return null;
        }

        $parse = explode('_', $languageCode);

        return strtolower($parse[0]);
    }

    private function loadPhoneNumbers(
        ?array $phoneData,
        ?string &$mobilePhone = null,
        ?string &$landingPhone = null
    ): void {
        if (null === $phoneData) {
            return;
        }
        if (!isset($phoneData['phone'], $phoneData['is_mobile'])) {
            return;
        }
        if (true === $phoneData['is_mobile']) {
            $mobilePhone = $phoneData['phone'];
        }
        if (true !== $phoneData['is_mobile']) {
            $landingPhone = $phoneData['phone'];
        }
    }

    private function addBillingInfo(
        AddressInterface $billing,
        CustomerInterface $customer,
        OrderInterface $order,
        ArrayObject &$details
    ): void {
        //Sylius does not require any phone number so we have to considere it null
        $billingPhone = null !== $billing->getPhoneNumber() ? $this->formatNumber(
            $billing->getPhoneNumber(),
            $billing->getCountryCode()
        ) : null;
        $this->loadPhoneNumbers($billingPhone, $billingMobilePhone, $billingLandingPhone);

        $details['billing'] = [
            'title' => $this->formatTitle($customer),
            'first_name' => $billing->getFirstName(),
            'last_name' => $billing->getLastName(),
            'company_name' => $billing->getCompany(),
            'email' => $customer->getEmail(),
            'mobile_phone_number' => $billingMobilePhone,
            'landline_phone_number' => $billingLandingPhone,
            'address1' => $billing->getStreet(),
            'address2' => null,
            'postcode' => $billing->getPostcode(),
            'city' => $billing->getCity(),
            'state' => $billing->getProvinceName(),
            'country' => $billing->getCountryCode(),
            'language' => $this->formatLanguageCode($order->getLocaleCode()),
        ];
    }

    private function addShippingInfo(
        AddressInterface $shipping,
        CustomerInterface $customer,
        OrderInterface $order,
        string $deliveryType,
        ArrayObject &$details
    ): void {
        $shippingPhone = null !== $shipping->getPhoneNumber() ? $this->formatNumber(
            $shipping->getPhoneNumber(),
            $shipping->getCountryCode()
        ) : null;
        $this->loadPhoneNumbers($shippingPhone, $shippingMobilePhone, $shippingLandingPhone);

        $details['shipping'] = [
            'title' => $this->formatTitle($customer),
            'first_name' => $shipping->getFirstName(),
            'last_name' => $shipping->getLastName(),
            'company_name' => $shipping->getCompany(),
            'email' => $customer->getEmail(),
            'mobile_phone_number' => $shippingMobilePhone,
            'landline_phone_number' => $shippingLandingPhone,
            'address1' => $shipping->getStreet(),
            'address2' => null,
            'postcode' => $shipping->getPostcode(),
            'city' => $shipping->getCity(),
            'state' => $shipping->getProvinceName(),
            'country' => $shipping->getCountryCode(),
            'language' => $this->formatLanguageCode($order->getLocaleCode()),
            'delivery_type' => $deliveryType,
        ];
    }

    private function alterPayPlugDetailsForOneClick(PaymentMethodInterface $paymentMethod, ArrayObject $details): ArrayObject
    {
        if (!$this->canSaveCardChecker->isAllowed($paymentMethod)) {
            return $details;
        }

        /** @var string|null $cardId */
        $cardId = $this->requestStack->getSession()->get('payplug_payment_method');

        if ((null === $cardId || self::PAYPLUG_CARD_ID_OTHER === $cardId) && $this->canSaveCardChecker->isAllowed(
            $paymentMethod
        )) {
            $details['allow_save_card'] = true;

            return $details;
        }

        if (null === $cardId) {
            return $details;
        }

        $card = $this->payplugCardRepository->find($cardId);

        if (!$card instanceof Card) {
            return $details;
        }

        $details['payment_method'] = $card->getExternalId();
        $details['initiator'] = 'PAYER';

        return $details;
    }

    private function alterPayPlugDetailsForDeferredCapture(PaymentMethodInterface $paymentMethod, ArrayObject $details): ArrayObject
    {
        if (!$this->payplugFeatureChecker->isDeferredCaptureEnabled($paymentMethod)) {
            return $details;
        }

        $details['authorized_amount'] = $details['amount'];
        unset($details['amount']);

        return $details;
    }

    private function alterOneyDetails(ArrayObject $details): ArrayObject
    {
        $details['payment_method'] = $this->requestStack->getSession()->get('oney_payment_method', 'oney_x3_with_fees');
        $details['auto_capture'] = true;
        $details['authorized_amount'] = $details['amount'];
        unset($details['amount']);

        $billing = $details['billing'];
        if (null === $billing['company_name']) {
            $billing['company_name'] = sprintf('%s %s', $billing['first_name'], $billing['last_name']);
        }
        $details['billing'] = $billing;

        $shipping = $details['shipping'];
        if (null === $shipping['company_name']) {
            $shipping['company_name'] = sprintf('%s %s', $shipping['first_name'], $shipping['last_name']);
        }
        $details['shipping'] = $shipping;

        return $details;
    }

    private function getCartContext(OrderInterface $order): array
    {
        /** @var Shipment $shipment */
        $shipment = $order->getShipments()->current();

        $expectedDeliveryDate = (new DateTime())->add(new DateInterval('P7D'))->format('Y-m-d');
        $deliveryType = $this->retrieveDeliveryType($shipment);
        $data = [];

        foreach ($order->getItems() as $orderItem) {
            $data[] = [
                'delivery_label' => (null !== $shipment->getMethod()) ? $shipment->getMethod()->getName() : 'none',
                'delivery_type' => $deliveryType,
                'expected_delivery_date' => $expectedDeliveryDate,
                'merchant_item_id' => (null !== $orderItem->getVariant()) ? $orderItem->getVariant()->getCode(
                ) : 'none',
                'brand' => $orderItem->getProductName(),
                'name' => $orderItem->getProductName().' '.$orderItem->getVariantName(),
                'total_amount' => $orderItem->getTotal(),
                'price' => $orderItem->getUnitPrice(),
                'quantity' => $orderItem->getQuantity(),
            ];
        }

        return ['cart' => $data];
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function retrieveDeliveryType(ShipmentInterface $shipment): string
    {
        // Possible delivery type : [storepickup, networkpickup, travelpickup, carrier, edelivery]
        return 'storepickup';
    }

    private function addPaymentMethodFieldToDetails(ArrayObject $details, string $gatewayFactoryName): ArrayObject
    {
        $paymentMethods = [
            BancontactGatewayFactory::FACTORY_NAME => BancontactGatewayFactory::PAYMENT_METHOD_BANCONTACT,
            ApplePayGatewayFactory::FACTORY_NAME => ApplePayGatewayFactory::PAYMENT_METHOD_APPLE_PAY,
            AmericanExpressGatewayFactory::FACTORY_NAME => AmericanExpressGatewayFactory::PAYMENT_METHOD_AMERICAN_EXPRESS,
        ];
        // match function is only supported by php 8. so can not use it here.
        foreach ($paymentMethods as $name => $method) {
            if ($gatewayFactoryName !== $name) {
                continue;
            }
            $details['payment_method'] = $method;
        }

        return $details;
    }
}
