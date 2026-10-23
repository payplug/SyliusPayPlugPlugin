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
 * allows (all countries when PayPlug sets no restriction). The model value is null when every
 * choice is selected, so that countries PayPlug adds later are included too.
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
        $codes = array_values($choices);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (mixed $countries): array => \is_array($countries) ? array_values(array_intersect($countries, $codes)) : $codes,
            static function (mixed $selected) use ($codes): ?array {
                $selected = \is_array($selected) ? array_values($selected) : [];
                $missing = array_diff($codes, $selected);

                return [] !== $selected && [] === $missing ? null : $selected;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'allowed_countries' => null,
                'multiple' => true,
                'required' => false,
                'choice_translation_domain' => false,
                'choices' => static function (Options $options): array {
                    /** @var list<string>|null $allowedCountries */
                    $allowedCountries = $options['allowed_countries'];

                    return self::buildChoices($allowedCountries);
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
     *
     * @return array<string, string> country name => country code
     */
    private static function buildChoices(?array $allowedCountries): array
    {
        $names = Countries::getNames();
        if (null === $allowedCountries) {
            return array_flip($names);
        }

        $choices = [];
        foreach ($allowedCountries as $code) {
            $choices[$names[$code] ?? $code] = $code;
        }

        return $choices;
    }
}
