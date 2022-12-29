<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSaveApplePayMethod;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsPayPlugSecretKeyValid;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

final class AmericanExpressGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    /**
     * @inheritdoc
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
                    new IsCanSaveApplePayMethod(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.ui.retrieve_secret_key_in_api_configuration_portal'),
                'help_html' => true,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $this->checkCreationRequirements(
                    ApplePayGatewayFactory::FACTORY_TITLE,
                    ApplePayGatewayFactory::FACTORY_NAME,
                    $event->getForm()
                );

                /** @phpstan-ignore-next-line */
                $formChannels = $event->getForm()->getParent()->getParent()->get('channels');
                $dataFormChannels = $formChannels->getData();
                /** @var ChannelInterface $dataFormChannel */
                foreach ($dataFormChannels as $key => $dataFormChannel) {
                    $baseCurrency = $dataFormChannel->getBaseCurrency();
                    if (null === $baseCurrency) {
                        continue;
                    }
                    $baseCurrencyCode = $baseCurrency->getCode();
                    if (ApplePayGatewayFactory::BASE_CURRENCY_CODE !== $baseCurrencyCode) {
                        $message = $this->translator->trans(
                            'payplug_sylius_payplug_plugin.form.base_currency_not_euro',
                            [
                                '#channel_code#' => $dataFormChannel->getCode(),
                                '#payment_method#' => ApplePayGatewayFactory::FACTORY_TITLE,
                            ]
                        );
                        $formChannels->get($key)->addError(new FormError($message));
                        $this->requestStack->getSession()->getFlashBag()->add('error', $message);
                    }
                }
            })
        ;
    }
}
