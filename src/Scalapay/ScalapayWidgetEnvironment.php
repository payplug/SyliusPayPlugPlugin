<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Scalapay;

/**
 * The `environment` attribute of the Scalapay widget. The merchant token PayPlug returns is a
 * Scalapay production token on the PayPlug production platform and a Scalapay integration token on
 * any other platform (QA, development), in TEST and LIVE mode alike. The platform is the one the
 * plugin authenticates against: the access token of every PayPlug call, the account call returning
 * the merchant token included, is issued there.
 */
final class ScalapayWidgetEnvironment
{
    public const INTEGRATION = 'integration';

    private const PRODUCTION_SCHEME = 'https';

    private const PRODUCTION_HOST = 'api.payplug.com';

    private ?string $attribute;

    public function __construct(string $payplugOauthBaseUrl)
    {
        $this->attribute = self::isProductionPlatform($payplugOauthBaseUrl) ? null : self::INTEGRATION;
    }

    /**
     * Null on the production platform: a Scalapay production token must not carry the attribute.
     */
    public function attribute(): ?string
    {
        return $this->attribute;
    }

    private static function isProductionPlatform(string $oauthBaseUrl): bool
    {
        $url = parse_url(trim($oauthBaseUrl));

        return \is_array($url) &&
            self::PRODUCTION_SCHEME === strtolower($url['scheme'] ?? '') &&
            self::PRODUCTION_HOST === strtolower($url['host'] ?? '');
    }
}
