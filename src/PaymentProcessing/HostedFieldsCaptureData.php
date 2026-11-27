<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\Exception\InvalidHostedFieldsSubmissionException;

/**
 * The Hosted Fields form submission data captured by PostPaymentSelectEventSubscriber and handed
 * to HostedFieldsPaymentProcessorInterface::process() — grouped into one object rather than
 * passed to process() as individual parameters (SonarCloud php:S107).
 *
 * The brand and the cardholder name come straight from the shopper's POST, so they are checked
 * here against the same rules as the checkout form: the Unified API accepts only these brands
 * and a UTF-8 `paymentMethod.details.fullName` of 5 to 45 characters.
 *
 * The name is composed (NFC) and trimmed like the browser does with normalize('NFC') and
 * String.prototype.trim() (Unicode spaces such as the no-break space and the BOM included, not only
 * ASCII whitespace, unlike PHP trim()), so both sides count the characters the shopper sees;
 * control characters, which the form cannot produce, are refused.
 */
final class HostedFieldsCaptureData
{
    public const ALLOWED_BRANDS = ['CB', 'VISA', 'MASTERCARD'];

    public const CARD_HOLDER_NAME_MIN_LENGTH = 5;

    public const CARD_HOLDER_NAME_MAX_LENGTH = 45;

    private const CARD_HOLDER_NAME_PADDING_PATTERN = '/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u';

    private const CONTROL_CHARACTER_PATTERN = '/\p{Cc}/u';

    public readonly string $selectedBrand;

    public readonly string $cardHolderName;

    public function __construct(
        public readonly string $hfToken,
        string $selectedBrand,
        public readonly bool $saveCard,
        public readonly HostedFieldsCardDetails $card,
        string $cardHolderName,
    ) {
        $this->selectedBrand = self::normaliseBrand($selectedBrand);
        $this->cardHolderName = self::normaliseCardHolderName($cardHolderName);
    }

    private static function normaliseBrand(string $selectedBrand): string
    {
        $brand = \strtoupper(\trim($selectedBrand));
        if (!\in_array($brand, self::ALLOWED_BRANDS, true)) {
            throw new InvalidHostedFieldsSubmissionException('Hosted Fields selectedBrand is not allowed.');
        }

        return $brand;
    }

    private static function normaliseCardHolderName(string $cardHolderName): string
    {
        // Checked first: the /u patterns below fail on invalid UTF-8.
        if (!\mb_check_encoding($cardHolderName, 'UTF-8')) {
            throw new InvalidHostedFieldsSubmissionException('Hosted Fields cardHolderName is not valid UTF-8.');
        }

        $composed = \Normalizer::normalize($cardHolderName, \Normalizer::FORM_C);
        $name = (string) \preg_replace(self::CARD_HOLDER_NAME_PADDING_PATTERN, '', \is_string($composed) ? $composed : $cardHolderName);
        if (1 === \preg_match(self::CONTROL_CHARACTER_PATTERN, $name)) {
            throw new InvalidHostedFieldsSubmissionException('Hosted Fields cardHolderName contains a control character.');
        }

        $length = \mb_strlen($name, 'UTF-8');
        if (self::CARD_HOLDER_NAME_MIN_LENGTH > $length || self::CARD_HOLDER_NAME_MAX_LENGTH < $length) {
            throw new InvalidHostedFieldsSubmissionException(\sprintf(
                'Hosted Fields cardHolderName must be between %d and %d characters.',
                self::CARD_HOLDER_NAME_MIN_LENGTH,
                self::CARD_HOLDER_NAME_MAX_LENGTH,
            ));
        }

        return $name;
    }
}
