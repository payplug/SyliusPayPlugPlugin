<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetSettings;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the Scalapay widget is shown (product page, cart, checkout) and the CSS selector of the
 * amount it reads on each of these pages. The fields write into the `scalapay_widget` block of
 * the gateway config through nested property paths.
 */
final class ScalapayWidgetTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (ScalapayWidgetSettings::PAGES as $page) {
            $builder
                ->add(self::enabledField($page), CheckboxType::class, [
                    'label' => 'payplug_sylius_payplug_plugin.ui.scalapay_widget.' . $page,
                    'required' => false,
                    'property_path' => sprintf('[%s][%s][enabled]', ScalapayWidgetSettings::CONFIG_KEY, $page),
                ])
                ->add(self::amountSelectorField($page), TextType::class, [
                    'label' => 'payplug_sylius_payplug_plugin.ui.scalapay_widget.amount_selector',
                    'help' => 'payplug_sylius_payplug_plugin.ui.scalapay_widget.amount_selector_help',
                    'help_translation_parameters' => ['%default%' => ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS[$page]],
                    'required' => false,
                    'property_path' => sprintf('[%s][%s][amount_selector]', ScalapayWidgetSettings::CONFIG_KEY, $page),
                ])
            ;
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $config = $event->getData();
            $event->setData(ScalapayWidgetSettings::withDefaults(\is_array($config) ? $config : []));
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            foreach (ScalapayWidgetSettings::PAGES as $page) {
                $this->validateAmountSelector($event->getForm(), $page);
            }
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [ScalapayGatewayConfigurationType::class];
    }

    public static function enabledField(string $page): string
    {
        return 'scalapay_widget_' . $page;
    }

    public static function amountSelectorField(string $page): string
    {
        return 'scalapay_widget_' . $page . '_amount_selector';
    }

    private function validateAmountSelector(FormInterface $form, string $page): void
    {
        $field = $form->get(self::amountSelectorField($page));
        $selector = $field->getData();

        if (null === $selector || '' === $selector) {
            if (true === $form->get(self::enabledField($page))->getData()) {
                $field->addError(new FormError($this->translator->trans('payplug_sylius_payplug_plugin.scalapay_widget.amount_selector_required', [], 'validators')));
            }

            return;
        }

        if (!\is_string($selector) || !ScalapayWidgetSettings::isValidAmountSelector($selector)) {
            $field->addError(new FormError($this->translator->trans('payplug_sylius_payplug_plugin.scalapay_widget.invalid_amount_selector', [], 'validators')));
        }
    }
}
