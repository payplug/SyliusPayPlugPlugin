<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSaveCards;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsOneyEnabled;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsPayPlugSecretKeyValid;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

final class OneyGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $validationGroups = ['Default', 'sylius'];

        $builder
            ->add('secretKey', TextType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.secret_key',
                'validation_groups' => $validationGroups,
                'constraints' => [
                    new NotBlank([
                        'message' => 'payplug_sylius_payplug_plugin.secret_key.not_blank',
                    ]),
                    new IsPayPlugSecretKeyValid(),
                    new IsOneyEnabled(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.ui.retrieve_secret_key_in_api_configuration_portal'),
                'help_html' => true,
            ])
            ->add('oneClick', CheckboxType::class, [
                'label' => 'payplug_sylius_payplug_plugin.form.one_click_enable',
                'validation_groups' => $validationGroups,
                'constraints' => [
                    new IsCanSaveCards(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.form.one_click_help'),
                'help_html' => true,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $this->checkCreationRequirements(
                    OneyGatewayFactory::FACTORY_TITLE,
                    OneyGatewayFactory::FACTORY_NAME,
                    $event->getForm()
                );

                /** @phpstan-ignore-next-line */
                $formChannels = $event->getForm()->getParent()->getParent()->get('channels');
                $dataFormChannels = $formChannels->getData();
                /** @var ChannelInterface $dataFormChannel */
                foreach ($dataFormChannels as $key => $dataFormChannel) {
                    $baseCurrency = $dataFormChannel->getBaseCurrency();
                    if ($baseCurrency === null) {
                        continue;
                    }
                    $baseCurrencyCode = $baseCurrency->getCode();
                    if ($baseCurrencyCode !== OneyGatewayFactory::BASE_CURRENCY_CODE) {
                        $message = $this->translator->trans(
                            'payplug_sylius_payplug_plugin.form.base_currency_not_euro', [
                                '#channel_code#' => $dataFormChannel->getCode(),
                                '#payment_method#' => OneyGatewayFactory::FACTORY_TITLE,
                            ]
                        );
                        $formChannels->get($key)->addError(new FormError($message));
                        $this->flashBag->add('error', $message);
                    }
                }
            })
        ;
    }
}
