<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Validator;

use libphonenumber\PhoneNumberUtil;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
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
            $fields[] = 'email';
        }

        if (!$this->isPhoneAddressValid($shippingAddress)) {
            $fields[] = 'shipping_address';
        }

        if (!$this->isPhoneAddressValid($billingAddress)) {
            $fields[] = 'billing_address';
        }

        return $fields;
    }

    private function isCustomerEmailValid(CustomerInterface $customer): bool
    {
        return \mb_strpos($customer->getEmail(), '+') === false;
    }

    private function isPhoneAddressValid(AddressInterface $address): bool
    {
        $phoneNumber = $address->getPhoneNumber();
        if (null === $phoneNumber) {
            return false;
        }
        $parsedNumber = $this->phoneNumberUtil->parse($phoneNumber, $address->getCountryCode());
        if (null === $parsedNumber || !$this->phoneNumberUtil->isValidNumber($parsedNumber)) {
            return false;
        }

        return $this->phoneNumberUtil->getNumberType($parsedNumber) === PhoneNumberType::MOBILE;
    }
}
