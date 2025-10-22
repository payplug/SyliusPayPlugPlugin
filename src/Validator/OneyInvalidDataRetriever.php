<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Validator;

use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Webmozart\Assert\Assert;

final class OneyInvalidDataRetriever
{
    /** @var \libphonenumber\PhoneNumberUtil */
    private $phoneNumberUtil;

    public function __construct()
    {
        $this->phoneNumberUtil = PhoneNumberUtil::getInstance();
    }

    public function getForOrder(OrderInterface $order): array
    {
        $fields = [];

        $customer = $order->getCustomer();
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();

        Assert::notNull($customer);
        Assert::notNull($shippingAddress);
        Assert::notNull($billingAddress);

        if (!$this->isCustomerEmailValid($customer)) {
            $fields['email'] = EmailType::class;
        }

        if (!$this->isPhoneAddressValid($shippingAddress)) {
            $fields['shipping_phone'] = TelType::class;
        }

        if (!$this->isPhoneAddressValid($billingAddress)) {
            $fields['billing_phone'] = TelType::class;
        }

        return $fields;
    }

    private function isCustomerEmailValid(CustomerInterface $customer): bool
    {
        return null !== $customer->getEmail() &&
            false === \mb_strpos($customer->getEmail(), '+');
    }

    private function isPhoneAddressValid(AddressInterface $address): bool
    {
        $phoneNumber = $address->getPhoneNumber();
        if (null === $phoneNumber) {
            return false;
        }

        try {
            $parsedNumber = $this->phoneNumberUtil->parse($phoneNumber, $address->getCountryCode());
            if (!$this->phoneNumberUtil->isValidNumber($parsedNumber)) {
                return false;
            }

            return PhoneNumberType::MOBILE === $this->phoneNumberUtil->getNumberType($parsedNumber);
        } catch (\Throwable) {
            return false;
        }
    }
}
