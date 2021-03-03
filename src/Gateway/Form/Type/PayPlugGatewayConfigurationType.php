<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsPayPlugSecretKeyValid;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PayPlugGatewayConfigurationType extends AbstractType
{
    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    private $translator;

    /** @var FlashBagInterface */
    private $flashBag;

    public function __construct(TranslatorInterface $translator, FlashBagInterface $flashBag)
    {
        $this->translator = $translator;
        $this->flashBag = $flashBag;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('secretKey', TextType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.secret_key',
                'constraints' => [
                    new NotBlank([
                        'message' => 'payplug_sylius_payplug_plugin.secret_key.not_blank',
                        'groups' => 'sylius',
                    ]),
                    new IsPayPlugSecretKeyValid(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.ui.retrieve_secret_key_in_api_configuration_portal'),
                'help_html' => true,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $data = $event->getData();

                $data['payum.http_client'] = '@payplug_sylius_payplug_plugin.api_client.payplug';

                $event->setData($data);
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
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
                    if ($baseCurrencyCode !== PayPlugGatewayFactory::BASE_CURRENCY_CODE) {
                        $message = $this->translator->trans(
                            'payplug_sylius_payplug_plugin.form.base_currency_not_euro', [
                                '#channel_code#' => $dataFormChannel->getCode(),
                                '#payment_method#' => PayPlugGatewayFactory::FACTORY_TITLE,
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
