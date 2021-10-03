<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit;

use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use PHPUnit\Framework\TestCase;

final class phoneNumberFormatTest extends TestCase
{
    /**
     * @dataProvider landlinePhoneNumbersDataProvider
     * @dataProvider mobilePhoneNumbersDataProvider
     */
    public function testFormatNumberMethod(string $input, string $isoCode, array $expectedOutput): void
    {
        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $parsed = $phoneNumberUtil->parse($input, $isoCode);

        self::assertEquals($expectedOutput['is_mobile'], $phoneNumberUtil->getNumberType($parsed) === PhoneNumberType::MOBILE);
    }

    public function landlinePhoneNumbersDataProvider(): \Generator
    {
        yield ['0123456791', 'FR', [
            'phone' => '+33123456791',
            'is_mobile' => false,
        ]];
        yield ['+33123456791', 'FR', [
            'phone' => '+33123456791',
            'is_mobile' => false,
        ]];
        yield ['33123456791', 'FR', [
            'phone' => '+33123456791',
            'is_mobile' => false,
        ]];
        yield ['0223456791', 'FR', [
            'phone' => '+33223456791',
            'is_mobile' => false,
        ]];
        yield ['0323456791', 'FR', [
            'phone' => '+33323456791',
            'is_mobile' => false,
        ]];
        yield ['0423456791', 'FR', [
            'phone' => '+33423456791',
            'is_mobile' => false,
        ]];
        yield ['0566778899', 'FR', [
            'phone' => '+33566778899',
            'is_mobile' => false,
        ]];
        yield ['0912131415', 'FR', [
            'phone' => '+33912131415',
            'is_mobile' => false,
        ]];
        yield ['912131415', 'FR', [
            'phone' => '+33912131415',
            'is_mobile' => false,
        ]];
        yield ['12131415', 'FR', [
            'phone' => null,
            'is_mobile' => null,
        ]];
        yield ['123', 'FR', [
            'phone' => null,
            'is_mobile' => null,
        ]];
        yield ['0102', 'FR', [
            'phone' => null,
            'is_mobile' => null,
        ]];
        yield ['023456789', 'BE', [
            'phone' => '+3223456789',
            'is_mobile' => false,
        ]];
        yield ['23456789', 'BE', [
            'phone' => '+3223456789',
            'is_mobile' => false,
        ]];
        yield ['3456789', 'BE', [
            'phone' => null,
            'is_mobile' => null,
        ]];
        yield ['023456789', 'DE', [
            'phone' => '+4923456789',
            'is_mobile' => false,
        ]];
        yield ['23456789', 'DE', [
            'phone' => '+4923456789',
            'is_mobile' => false,
        ]];
        yield ['3456789', 'DE', [
            'phone' => '+493456789',
            'is_mobile' => false,
        ]];
        yield ['12', 'DE', [
            'phone' => null,
            'is_mobile' => null,
        ]];
    }

    public function mobilePhoneNumbersDataProvider(): \Generator
    {
        yield ['0615151515', 'FR', [
            'phone' => '+33615151515',
            'is_mobile' => true,
        ]];
        yield ['0733445566', 'FR', [
            'phone' => '+33733445566',
            'is_mobile' => true,
        ]];
        yield ['633445566', 'FR', [
            'phone' => '+33633445566',
            'is_mobile' => true,
        ]];
        yield ['33445566', 'FR', [
            'phone' => null,
            'is_mobile' => null,
        ]];
    }
}
