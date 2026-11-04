<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Lists the regions an address's phone number is read in, in order — shared by
 * OrderAddressDtoCreator (Unified API/Hosted Fields) and the legacy PayPlugPaymentDataCreator.
 */
final class PhoneRegionResolver
{
    /**
     * Overseas territories sharing the French numbering, where shoppers often enter a
     * metropolitan number in national format.
     */
    private const FRENCH_NUMBERING_TERRITORIES = ['GP', 'MQ', 'RE', 'GF', 'YT', 'PM', 'BL', 'MF', 'NC', 'PF', 'WF'];

    /**
     * Territories sharing the +590 numbering with Guadeloupe: libphonenumber attributes a
     * shared number to the first of these regions it is valid in.
     */
    private const SHARED_NUMBERING_REGIONS = [
        'BL' => ['GP', 'MF'],
        'MF' => ['GP', 'BL'],
    ];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @return list<string> the address country first, then the regions sharing its numbering, then
     *      FR for an overseas territory sharing the French numbering
     */
    public static function resolve(string $countryCode): array
    {
        $countryCode = \strtoupper($countryCode);
        if (!\in_array($countryCode, self::FRENCH_NUMBERING_TERRITORIES, true)) {
            return [$countryCode];
        }

        return [$countryCode, ...(self::SHARED_NUMBERING_REGIONS[$countryCode] ?? []), 'FR'];
    }
}
