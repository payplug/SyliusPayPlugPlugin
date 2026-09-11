<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug',
        'label' => 'payplug_sylius_payplug_plugin.ui.payplug_gateway_label',
        'priority' => 100,
    ],
)]
final class PayPlugGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = PayPlugGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = PayPlugGatewayFactory::BASE_CURRENCY_CODE;

    /**
     * Only `integrated_payment` requires every associated channel to be EUR; the redirected
     * and `hosted_fields` display modes both work in any currency.
     *
     * The mode is read back through `resolveDisplayMode()` rather than off a display-mode key:
     * `DISPLAY_MODE_FIELD` is an unmapped admin form field and never reaches the persisted config,
     * which instead carries the two `INTEGRATED_PAYMENT`/`HOSTED_FIELDS` booleans written by
     * `resolveDisplayModeFlags()`. Going through the canonical reader also inherits its
     * hosted-fields-wins tie-break when both flags are somehow true.
     *
     * @param array<int|string, mixed> $gatewayConfig Mapped gateway configuration, as stored on GatewayConfig.
     */
    public function shouldValidateBaseCurrency(array $gatewayConfig): bool
    {
        return PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT === PayPlugGatewayFactory::resolveDisplayMode($gatewayConfig);
    }

    /**
     * shouldValidateBaseCurrency() above only ever lets this fire for `integrated_payment` mode
     * (redirected/hosted_fields both return false there), so this message can be specific to
     * that mode rather than the generic per-gateway wording.
     */
    public function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        return $this->translator->trans('payplug_sylius_payplug_plugin.form.integrated_payment_currency_incompatible');
    }
}
