<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use libphonenumber\PhoneNumberFormat as PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil as PhoneNumberUtil;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        /** @var CustomerInterface $customer */
        $customer = $order->getCustomer();

        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['amount'] = $payment->getAmount();
        $details['currency'] = $payment->getCurrencyCode();

        $details['metadata'] = [
            'customer_id' => $customer->getId(),
            'order_number' => $order->getNumber(),
        ];

        // DSP2 fields
        /** @var AddressInterface $shipping */
        $shipping = $order->getShippingAddress();
        /** @var AddressInterface $billing */
        $billing = $order->getBillingAddress();

        $delivery_type = $shipping->getId() == $billing->getId() ? 'BILLING' : 'OTHER';

        //Sylius does not require any phone number so we have to considere it null
        $billing_phone = $billing->getPhoneNumber() !== null ? $this->formatNumber($billing->getPhoneNumber(), $billing->getCountryCode()) : null;
        $billingMobilePhone = null;
        $billingLandingPhone = null;

        if (isset($billing_phone['phone'], $billing_phone['is_mobile'])) {
            if ($billing_phone['is_mobile'] === true) {
                $billingMobilePhone = $billing_phone['phone'];
            }
            if ($billing_phone['is_mobile'] !== true) {
                $billingLandingPhone = $billing_phone['phone'];
            }
        }

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

        $shipping_phone = $shipping->getPhoneNumber() !== null ? $this->formatNumber($shipping->getPhoneNumber(), $shipping->getCountryCode()) : null;
        $shippingMobilePhone = null;
        $shippingLandingPhone = null;

        if (isset($shipping_phone['phone'], $shipping_phone['is_mobile'])) {
            if ($shipping_phone['is_mobile'] === true) {
                $shippingMobilePhone = $shipping_phone['phone'];
            }
            if ($shipping_phone['is_mobile'] !== true) {
                $shippingLandingPhone = $shipping_phone['phone'];
            }
        }
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
            'delivery_type' => $delivery_type,
        ];

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() == 'array';
    }

    public function formatTitle(CustomerInterface $customer): ?string
    {
        $gender = $customer->getGender();

        return $gender == 'm' ? 'mr' : ($gender == 'f' ? 'mrs' : null);
    }

    public function formatNumber(string $phoneNumber, ?string $isoCode): array
    {
        $phone_util = PhoneNumberUtil::getInstance();
        $parsed = $phone_util->parse($phoneNumber, $isoCode);

        if (!$phone_util->isValidNumber($parsed)) {
            return [
                'phone' => null,
                'is_mobile' => null,
            ];
        }

        $formated = $phone_util->format($parsed, PhoneNumberFormat::E164);

        return [
            'phone' => $formated,
            'is_mobile' => $phone_util->getNumberType($parsed) == 1,
        ];
    }

    public function formatLanguageCode(?string $languageCode): ?string
    {
        if (null === $languageCode) {
            return null;
        }

        $parse = explode('_', $languageCode);

        return strtolower($parse[0]);
    }
}
