<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractGatewayConfigurationType extends AbstractType
{
    public const VALIDATION_GROUPS = ['Default', 'sylius'];

    protected string $gatewayFactoryTitle = '';

    protected string $gatewayBaseCurrencyCode = PayPlugGatewayFactory::BASE_CURRENCY_CODE;

    public function __construct(
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('live', CheckboxType::class, [
                'block_name' => 'payplug_checkbox',
                'label' => 'payplug_sylius_payplug_plugin.ui.live',
                'help' => 'payplug_sylius_payplug_plugin.ui.live_help',
                'help_html' => true,
                'required' => false,
            ])
            ->add('renew_oauth', CheckboxType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.renew_oauth',
                'help' => 'payplug_sylius_payplug_plugin.ui.renew_oauth_help',
                'help_html' => true,
                'mapped' => false,
                'required' => false,
            ])
        ;
    }

    /**
     * Hook for subtypes to scope the base-currency-per-channel restriction enforced by
     * PaymentMethodTypeExtension.
     * Default: always enforced, preserving today's behavior for every gateway that doesn't
     * override this (Bancontact, American Express, Scalapay, Wero, Oney...).
     *
     * @see baseCurrencyViolationMessage() Companion hook customizing the message this guards.
     *
     * @param array<array-key, mixed> $gatewayConfig Mapped gateway configuration, as stored on GatewayConfig.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function shouldValidateBaseCurrency(array $gatewayConfig): bool
    {
        return true;
    }

    /**
     * Hook for subtypes to customize the currency-violation message. Default matches today's
     * generic wording, used by every gateway subtype that doesn't override it (Bancontact,
     * American Express, Scalapay, Wero, Oney...).
     *
     * @see shouldValidateBaseCurrency() Companion hook scoping when this message is used.
     */
    public function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        return $this->translator->trans(
            'payplug_sylius_payplug_plugin.form.base_currency_not_euro',
            [
                '#channel_code#' => $channel->getCode(),
                '#payment_method#' => $this->gatewayFactoryTitle,
            ],
        );
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->gatewayBaseCurrencyCode;
    }
}
