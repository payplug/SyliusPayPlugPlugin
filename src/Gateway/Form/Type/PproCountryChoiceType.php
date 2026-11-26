<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Billing countries a PPRO payment method is displayed for, chosen among the countries PayPlug
 * allows (all countries when PayPlug sets no restriction) that are also in `available_countries`
 * when that option is set.
 *
 * The merchant only sees the choices, but the restriction is kept over every country PayPlug
 * allows: a stored country left out of the choices (for instance not enabled in Sylius for now)
 * is kept on submit, and the model value is null only when the result covers every country
 * PayPlug allows, so that saving the page never widens a restriction on its own. With no choice
 * at all the field is disabled, so the stored value is left untouched.
 *
 * The `autocomplete` and `tom_select_options` options belong to Symfony UX Autocomplete, which the
 * Sylius admin ships; they are declared here too so the type also resolves without it.
 */
final class PproCountryChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $choices */
        $choices = $options['choices'];
        $visible = array_values($choices);
        /** @var list<string>|null $allowedCountries */
        $allowedCountries = $options['allowed_countries'];
        $allowed = $allowedCountries ?? Countries::getCountryCodes();
        $stored = null;

        $builder->addModelTransformer(new CallbackTransformer(
            static function (mixed $countries) use ($visible, &$stored): array {
                $stored = \is_array($countries) ? array_values($countries) : null;

                return null === $stored ? $visible : array_values(array_intersect($stored, $visible));
            },
            static function (mixed $selected) use ($visible, $allowed, &$stored): ?array {
                $selected = \is_array($selected) ? array_values($selected) : [];
                if ([] === $selected && null === $stored) {
                    return [];
                }

                $outOfView = array_diff($stored ?? $allowed, $visible);
                $kept = array_values(array_intersect($allowed, array_merge($selected, $outOfView)));

                return [] !== $kept && [] === array_diff($allowed, $kept) ? null : $kept;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'allowed_countries' => null,
                'available_countries' => null,
                'multiple' => true,
                'required' => false,
                'choice_translation_domain' => false,
                'choices' => static function (Options $options): array {
                    /** @var list<string>|null $allowedCountries */
                    $allowedCountries = $options['allowed_countries'];
                    /** @var list<string>|null $availableCountries */
                    $availableCountries = $options['available_countries'];

                    return self::buildChoices($allowedCountries, $availableCountries);
                },
                'autocomplete' => true,
                'max_results' => 500,
                'tom_select_options' => [
                    'plugins' => ['checkbox_options'],
                    'hideSelected' => false,
                    'closeAfterSelect' => false,
                ],
            ])
            ->setAllowedTypes('allowed_countries', ['null', 'string[]'])
            ->setAllowedTypes('available_countries', ['null', 'string[]'])
            ->setNormalizer('disabled', static fn (Options $options, mixed $disabled): bool => true === $disabled || [] === $options['choices'])
        ;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'payplug_ppro_country_choice';
    }

    /**
     * @param list<string>|null $allowedCountries
     * @param list<string>|null $availableCountries
     *
     * @return array<string, string> country name => country code
     */
    private static function buildChoices(?array $allowedCountries, ?array $availableCountries): array
    {
        $names = Countries::getNames();
        $codes = $allowedCountries ?? array_keys($names);
        if (null !== $availableCountries) {
            $codes = array_intersect($codes, $availableCountries);
        }

        $choices = [];
        foreach ($codes as $code) {
            $choices[$names[$code] ?? $code] = $code;
        }

        return $choices;
    }
}
