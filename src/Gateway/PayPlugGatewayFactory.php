<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class PayPlugGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug';

    public const FACTORY_TITLE = 'PayPlug';

    // Custom gateway configuration keys
    public const ONE_CLICK = 'oneClick';

    public const INTEGRATED_PAYMENT = 'integratedPayment';

    public const DEFERRED_CAPTURE = 'deferredCapture';

    public const HOSTED_FIELDS = 'hostedFields';

    public const HF_IDENTIFIER = 'hfIdentifier';

    public const HF_SUB_MERCHANT_ID = 'hfSubMerchantId';

    // Unmapped admin form field driving INTEGRATED_PAYMENT/HOSTED_FIELDS below
    public const DISPLAY_MODE_FIELD = 'hostedFieldsMode';

    public const DISPLAY_MODE_INTEGRATED_PAYMENT = 'integrated_payment';

    public const DISPLAY_MODE_HOSTED_FIELDS = 'hosted_fields';

    /**
     * Derives the admin form radio's initial selection from persisted config.
     * Hosted Fields wins if both flags are somehow true, since only it carries the
     * mandatory Account ID / SubMerchant ID fields the merchant would otherwise lose sight of.
     */
    public static function resolveDisplayMode(array $config): ?string
    {
        if (true === ($config[self::HOSTED_FIELDS] ?? false)) {
            return self::DISPLAY_MODE_HOSTED_FIELDS;
        }
        if (true === ($config[self::INTEGRATED_PAYMENT] ?? false)) {
            return self::DISPLAY_MODE_INTEGRATED_PAYMENT;
        }

        return null;
    }

    /**
     * Derives the two persisted booleans from the submitted radio value.
     * Always returns both keys explicitly (rather than only the "true" one) so that switching
     * away from a previously-selected mode clears the stale flag instead of leaving it behind.
     *
     * @return array{integratedPayment: bool, hostedFields: bool}
     */
    public static function resolveDisplayModeFlags(?string $displayMode): array
    {
        return [
            self::INTEGRATED_PAYMENT => self::DISPLAY_MODE_INTEGRATED_PAYMENT === $displayMode,
            self::HOSTED_FIELDS => self::DISPLAY_MODE_HOSTED_FIELDS === $displayMode,
        ];
    }

    /**
     * @param array<string, mixed> $rawFormData Display-mode/HF-identifier/HF-sub-merchant-id
     *                                          values as submitted (assembled from the config
     *                                          form's already-submitted child forms at
     *                                          POST_SUBMIT, not PRE_SUBMIT's raw payload).
     *
     * @return list<string> Config keys (HF_IDENTIFIER / HF_SUB_MERCHANT_ID) that are blank
     *                       while hosted_fields is selected; empty if hosted_fields isn't selected
     *                       or both fields are filled.
     */
    public static function missingHostedFieldsRequirements(array $rawFormData): array
    {
        if (self::DISPLAY_MODE_HOSTED_FIELDS !== ($rawFormData[self::DISPLAY_MODE_FIELD] ?? null)) {
            return [];
        }

        $missing = [];
        if (self::isBlank($rawFormData[self::HF_IDENTIFIER] ?? '')) {
            $missing[] = self::HF_IDENTIFIER;
        }
        if (self::isBlank($rawFormData[self::HF_SUB_MERCHANT_ID] ?? '')) {
            $missing[] = self::HF_SUB_MERCHANT_ID;
        }

        return $missing;
    }

    private static function isBlank(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return true;
        }

        return '' === trim((string) $value);
    }
}
