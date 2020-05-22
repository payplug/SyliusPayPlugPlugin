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

        $deliveryType = $shipping->getId() == $billing->getId() ? 'BILLING' : 'OTHER';

        //Sylius does not require any phone number so we have to considere it null
        $billingPhone = $billing->getPhoneNumber() !== null ? $this->formatNumber($billing->getPhoneNumber(), $billing->getCountryCode()) : null;
        $billingMobilePhone = null;
        $billingLandingPhone = null;
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

        $shippingPhone = $shipping->getPhoneNumber() !== null ? $this->formatNumber($shipping->getPhoneNumber(), $shipping->getCountryCode()) : null;
        $shippingMobilePhone = null;
        $shippingLandingPhone = null;
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
        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $parsed = $phoneNumberUtil->parse($phoneNumber, $isoCode);

        if (!$phoneNumberUtil->isValidNumber($parsed)) {
            return [
                'phone' => null,
                'is_mobile' => null,
            ];
        }

        $formated = $phoneNumberUtil->format($parsed, PhoneNumberFormat::E164);

        return [
            'phone' => $formated,
            'is_mobile' => $phoneNumberUtil->getNumberType($parsed) == 1,
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
        if ($phoneData['is_mobile'] === true) {
            $mobilePhone = $phoneData['phone'];
        }
        if ($phoneData['is_mobile'] !== true) {
            $landingPhone = $phoneData['phone'];
        }
    }
}
