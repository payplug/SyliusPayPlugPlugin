<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Scalapay;

/**
 * The attributes of one `<scalapay-widget>` tag. A null attribute is left out of the tag.
 */
final class ScalapayWidget
{
    /**
     * @param string $amountSelectors JSON array of CSS selectors
     */
    public function __construct(
        public readonly string $type,
        public readonly string $merchantToken,
        public readonly string $amountSelectors,
        public readonly string $locale,
        public readonly ?string $amountSeparator,
        public readonly ?string $environment,
    ) {
    }
}
