<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PproEligibilityTypeExtension;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checks the merchant settings submitted for a PPRO payment method against the PayPlug limits
 * stored for it. When the limits were never stored, only the rules that need no limit apply.
 */
final class PproConfigurationValidator
{
    private const DOMAIN = 'validators';

    private const CURRENCY = 'EUR';

    public function __construct(
        private TranslatorInterface $translator,
        private MoneyFormatterInterface $moneyFormatter,
    ) {
    }

    /**
     * @param int|null $min amount in cents, null when left empty
     * @param int|null $max amount in cents, null when left empty
     * @param list<string>|null $countries null when every country is selected
     *
     * @return array<string, list<string>> translated messages keyed by form field name
     */
    public function violations(?PproConfig $stored, ?int $min, ?int $max, ?array $countries): array
    {
        $violations = [
            PproEligibilityTypeExtension::MIN_AMOUNT_FIELD => $this->minViolations($stored, $min, $max),
            PproEligibilityTypeExtension::MAX_AMOUNT_FIELD => $this->maxViolations($stored, $max),
            PproEligibilityTypeExtension::COUNTRIES_FIELD => $this->countryViolations($stored?->allowedCountries, $countries),
        ];

        return array_filter($violations, static fn (array $messages): bool => [] !== $messages);
    }

    /**
     * @return list<string>
     */
    private function minViolations(?PproConfig $stored, ?int $min, ?int $max): array
    {
        $messages = [];
        $defaultMin = $stored?->defaultMin;
        $defaultMax = $stored?->defaultMax;

        if (null !== $min && null !== $defaultMin && $min < $defaultMin) {
            $messages[] = $this->boundsMessage('min_below_api_min', $defaultMin, $defaultMax);
        }

        $effectiveMin = $min ?? $defaultMin;
        $effectiveMax = $max ?? $defaultMax;
        if (null !== $effectiveMin && null !== $effectiveMax && $effectiveMin > $effectiveMax) {
            $messages[] = $this->message('min_greater_than_max');
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function maxViolations(?PproConfig $stored, ?int $max): array
    {
        $defaultMax = $stored?->defaultMax;

        if (null !== $max && null !== $defaultMax && $max > $defaultMax) {
            return [$this->boundsMessage('max_above_api_max', $stored?->defaultMin, $defaultMax)];
        }

        return [];
    }

    /**
     * @param list<string>|null $allowedCountries
     * @param list<string>|null $countries
     *
     * @return list<string>
     */
    private function countryViolations(?array $allowedCountries, ?array $countries): array
    {
        if ([] === $countries) {
            return [$this->message('no_country')];
        }

        if (null === $countries || null === $allowedCountries) {
            return [];
        }

        $notAllowed = array_values(array_diff($countries, $allowedCountries));

        return [] === $notAllowed ? [] : [$this->message('country_not_allowed', ['%countries%' => implode(', ', $notAllowed)])];
    }

    private function boundsMessage(string $key, ?int $defaultMin, ?int $defaultMax): string
    {
        return $this->message($key, [
            '%min%' => $this->formatAmount($defaultMin),
            '%max%' => $this->formatAmount($defaultMax),
        ]);
    }

    private function formatAmount(?int $amount): string
    {
        return null === $amount ? '' : $this->moneyFormatter->format($amount, self::CURRENCY, $this->translator->getLocale());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function message(string $key, array $parameters = []): string
    {
        return $this->translator->trans('payplug_sylius_payplug_plugin.ppro.' . $key, $parameters, self::DOMAIN);
    }
}
