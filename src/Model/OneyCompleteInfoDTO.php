<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Model;

use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class OneyCompleteInfoDTO
{
    /** @var string */
    public $phone;

    /** @var string */
    #[Assert\Email]
    public $email;

    /** @var string */
    public $countryCode;

    #[Assert\Callback]
    public function validatePhoneNumber(ExecutionContextInterface $context): void
    {
        if (null === $this->phone) {
            return;
        }

        try {
            $phoneNumberUtil = PhoneNumberUtil::getInstance();
            $parsedNumber = $phoneNumberUtil->parse($this->phone, $this->countryCode);
            if (
                !$phoneNumberUtil->isValidNumber($parsedNumber) ||
                PhoneNumberType::MOBILE !== $phoneNumberUtil->getNumberType($parsedNumber)
            ) {
                throw new \InvalidArgumentException('Not a valid mobile phone number');
            }
        } catch (\Throwable) {
            $context->buildViolation('payplug_sylius_payplug_plugin.oney.not_valid_phone_number')
                ->atPath('phone')
                ->addViolation();
        }
    }
}
