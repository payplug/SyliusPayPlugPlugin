<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Maps a Sylius customer's gender ('m'/'f') to the honorific title PayPlug's APIs expect — shared
 * by OrderAddressDtoCreator (Unified API/Hosted Fields, uppercase "MR"/"MRS") and the legacy
 * PayPlugPaymentDataCreator (lowercase "mr"/"mrs"), so the underlying mapping rule only lives in
 * one place even though each API expects a different casing.
 */
final class CustomerTitleResolver
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function resolve(string $gender): ?string
    {
        return match ($gender) {
            'm' => 'MR',
            'f' => 'MRS',
            default => null,
        };
    }
}
