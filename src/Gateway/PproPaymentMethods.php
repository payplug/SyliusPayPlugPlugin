<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

/**
 * The PPRO payment methods this plugin ships, keyed by gateway factory name, mapped to their key
 * under `payment_methods` in the PayPlug `/account` payload. Display eligibility (amount bounds and
 * billing countries) applies to every factory listed here and to no other.
 *
 * Satispay, MyBank, iDEAL and Bizum are PPRO methods too but have no gateway in this plugin yet:
 * adding one means one line here once its gateway exists.
 */
final class PproPaymentMethods
{
    public const API_KEY_BY_FACTORY = [
        BancontactGatewayFactory::FACTORY_NAME => 'bancontact',
        WeroGatewayFactory::FACTORY_NAME => 'wero',
        ScalapayGatewayFactory::FACTORY_NAME => 'scalapay',
    ];

    public static function isPpro(?string $factoryName): bool
    {
        return null !== self::apiKey($factoryName);
    }

    public static function apiKey(?string $factoryName): ?string
    {
        if (null === $factoryName) {
            return null;
        }

        return self::API_KEY_BY_FACTORY[$factoryName] ?? null;
    }
}
