<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Composer\InstalledVersions;

/**
 * Builds the integration identifier string sent as CommonFieldsDto::$description on every Unified
 * API payment-creation request — PayPlug's support/debugging teams use it to identify which
 * Sylius/plugin/UPC version combination a given payment came from. Despite the Unified API's own
 * documentation describing "description" as optional, a real staging 400 response
 * ("The parameter \"description\" is missing.") showed it's actually required in practice — the
 * live API is treated as the source of truth over the doc here.
 */
final class IntegrationDescriptionProvider
{
    private const UNKNOWN_VERSION = 'unknown';

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function build(): string
    {
        return \sprintf(
            'Sylius-%s/SyliusPayPlugPlugin-%s/UPC-%s',
            self::version('sylius/sylius'),
            self::version('payplug/sylius-payplug-plugin'),
            self::version('payplug/unified-plugin-core'),
        );
    }

    /**
     * Prefers a Composer branch alias (e.g. "2.2.0" for a root app requiring
     * "payplug/sylius-payplug-plugin": "dev-main as 2.2.0") over the raw branch name
     * ("dev-main") that InstalledVersions::getPrettyVersion() alone would return — the alias is
     * the actual semantic version the app declares itself as running, while the branch name is an
     * implementation detail of how that version currently gets installed.
     */
    private static function version(string $packageName): string
    {
        try {
            $prettyVersion = InstalledVersions::getPrettyVersion($packageName);
        } catch (\OutOfBoundsException) {
            return self::UNKNOWN_VERSION;
        }

        foreach (InstalledVersions::getAllRawData() as $installed) {
            $aliases = $installed['versions'][$packageName]['aliases'] ?? [];
            if ([] !== $aliases) {
                return $aliases[0];
            }
        }

        return $prettyVersion ?? self::UNKNOWN_VERSION;
    }
}
