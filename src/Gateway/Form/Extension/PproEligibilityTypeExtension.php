<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\BancontactGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PproCountryChoiceType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\WeroGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\GatewayCredentials;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfig;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Merchant narrowing of the PayPlug limits of a PPRO payment method. The fields write into the
 * `payment_method` block of the gateway config through nested property paths, so the PayPlug
 * limits stored next to them are kept on save. Amounts are entered in euros and stored in cents;
 * an empty amount follows the PayPlug limit, shown as the field's placeholder.
 *
 * Before the PayPlug connection there are no limits to narrow, so the fields are disabled.
 */
final class PproEligibilityTypeExtension extends AbstractTypeExtension
{
    public const MIN_AMOUNT_FIELD = 'ppro_min_amount';

    public const MAX_AMOUNT_FIELD = 'ppro_max_amount';

    public const COUNTRIES_FIELD = 'ppro_countries';

    private const AMOUNT_PATH = '[' . PproConfig::CONFIG_KEY . '][configured_amount][%s]';

    private const COUNTRIES_PATH = '[' . PproConfig::CONFIG_KEY . '][configured_countries]';

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $config = $event->getData();
            $config = \is_array($config) ? $config : [];
            $stored = PproConfig::fromArray($config[PproConfig::CONFIG_KEY] ?? null);
            $disabled = !GatewayCredentials::isConnected($config);

            $event->getForm()
                ->add(self::MIN_AMOUNT_FIELD, MoneyType::class, self::amountOptions('min', $disabled, $stored?->defaultMin))
                ->add(self::MAX_AMOUNT_FIELD, MoneyType::class, self::amountOptions('max', $disabled, $stored?->defaultMax))
                ->add(self::COUNTRIES_FIELD, PproCountryChoiceType::class, [
                    'label' => 'payplug_sylius_payplug_plugin.ui.ppro.countries_label',
                    'allowed_countries' => $stored?->allowedCountries,
                    'property_path' => self::COUNTRIES_PATH,
                    'disabled' => $disabled,
                ])
            ;
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [
            BancontactGatewayConfigurationType::class,
            WeroGatewayConfigurationType::class,
            ScalapayGatewayConfigurationType::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function amountOptions(string $side, bool $disabled, ?int $apiLimit): array
    {
        return [
            'attr' => null === $apiLimit ? [] : ['placeholder' => self::formatEuros($apiLimit)],
            'label' => 'payplug_sylius_payplug_plugin.ui.ppro.' . $side . '_amount',
            'currency' => 'EUR',
            'required' => false,
            'invalid_message' => 'payplug_sylius_payplug_plugin.ppro.invalid_amount',
            'property_path' => sprintf(self::AMOUNT_PATH, $side),
            'disabled' => $disabled,
        ];
    }

    /**
     * In the same format as the field's own value, so the placeholder reads like an entry.
     */
    private static function formatEuros(int $cents): string
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);
        $formatter->setAttribute(\NumberFormatter::GROUPING_USED, 0);

        return (string) $formatter->format($cents / 100);
    }
}
