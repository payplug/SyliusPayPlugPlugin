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
     * @param array<int|string, mixed> $rawFormData
     */
    protected function shouldValidateBaseCurrency(array $rawFormData): bool
    {
        return PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT === ($rawFormData[PayPlugGatewayFactory::DISPLAY_MODE_FIELD] ?? null);
    }

    /**
     * shouldValidateBaseCurrency() above only ever lets this fire for `integrated_payment` mode
     * (redirected/hosted_fields both return false there), so this message can be specific to
     * that mode rather than the generic per-gateway wording.
     */
    protected function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        return $this->translator->trans('payplug_sylius_payplug_plugin.form.integrated_payment_currency_incompatible');
    }
}
