<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Bridge\Spl\ArrayObject;

use libphonenumber\PhoneNumberUtil as PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat as PhoneNumberFormat;


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

        $customer = $order->getCustomer();

        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['amount'] = $payment->getAmount();
        $details['currency'] = $payment->getCurrencyCode();

        $details['metadata'] = [
            'customer_id' => $customer->getId(),
            'order_number' => $order->getNumber(),
        ];

        // DSP2 fields
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();

        $delivery_type = $shipping->getId() == $billing->getId() ? 'BILLING' : 'OTHER';

        $billing_phone = $this->formatNumber($billing->getPhoneNumber(),$billing->getCountryCode());
        $details['billing'] = array(
            'title' => $this->formatTitle($customer),
            'first_name' => $billing->getFirstName(),
            'last_name' => $billing->getLastName(),
            'company_name' => $billing->getCompany(),
            'email' => $customer->getEmail(),
            'mobile_phone_number' => $billing_phone['phone'] && $billing_phone['is_mobile'] ? $billing_phone['phone'] : null,
            'landline_phone_number' => $billing_phone['phone'] && !$billing_phone['is_mobile'] ? $billing_phone['phone'] : null,
            'address1' => $billing->getStreet(),
            'address2' => null,
            'postcode' => $billing->getPostcode(),
            'city' => $billing->getCity(),
            'state' => $billing->getProvinceName(),
            'country' => $billing->getCountryCode(),
            'language' => $this->formatLanguageCode($order->getLocaleCode()),
        );

        $shipping_phone = $this->formatNumber($shipping->getPhoneNumber(),$shipping->getCountryCode());
        $details['shipping'] = array(
            'title' => $this->formatTitle($customer),
            'first_name' => $shipping->getFirstName(),
            'last_name' => $shipping->getLastName(),
            'company_name' => $shipping->getCompany(),
            'email' => $customer->getEmail(),
            'mobile_phone_number' => $shipping_phone['phone'] && $shipping_phone['is_mobile'] ? $shipping_phone['phone'] : null,
            'landline_phone_number' => $shipping_phone['phone'] && !$shipping_phone['is_mobile'] ? $shipping_phone['phone'] : null,
            'address1' => $shipping->getStreet(),
            'address2' => null,
            'postcode' => $shipping->getPostcode(),
            'city' => $shipping->getCity(),
            'state' => $shipping->getProvinceName(),
            'country' => $shipping->getCountryCode(),
            'language' => $this->formatLanguageCode($order->getLocaleCode()),
            'delivery_type' => $delivery_type,
        );

        $request->setResult((array)$details);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() == 'array';
    }

    public function formatTitle($address)
    {
        $gender = $address->getGender();
        return $gender == 'm' ? 'mr' : ($gender == 'f' ? 'mrs' : null);
    }

    public function formatNumber($phone_number,$iso_code) : array
    {
        $phone_util = PhoneNumberUtil::getInstance();
        $parsed = $phone_util->parse($phone_number, $iso_code);

        if(!$phone_util->isValidNumber($parsed)) {
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

    public function formatLanguageCode($language_code) : string
    {
        $parse = explode('_',$language_code);
        return strtolower($parse[0]);
    }
}
