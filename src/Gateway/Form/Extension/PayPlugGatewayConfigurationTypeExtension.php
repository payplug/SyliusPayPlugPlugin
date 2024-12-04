<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSaveCards;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PayPlugGatewayConfigurationTypeExtension extends AbstractTypeExtension
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(PayPlugGatewayFactory::ONE_CLICK, CheckboxType::class, [
                'block_name' => 'payplug_checkbox',
                'label' => 'payplug_sylius_payplug_plugin.form.one_click_enable',
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'constraints' => [
                    new IsCanSaveCards(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.form.one_click_help'),
                'help_html' => true,
                'required' => false,
            ])
            ->add(PayPlugGatewayFactory::INTEGRATED_PAYMENT, CheckboxType::class, [
                'block_name' => 'payplug_checkbox',
                'label' => 'payplug_sylius_payplug_plugin.form.integrated_payment_enable',
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'required' => false,
            ])
            ->add(PayPlugGatewayFactory::DEFERRED_CAPTURE, CheckboxType::class, [
                'block_name' => 'payplug_checkbox',
                'label' => 'payplug_sylius_payplug_plugin.form.deferred_capture_enable',
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'help' => 'payplug_sylius_payplug_plugin.form.deferred_capture_help',
                'help_html' => true,
                'required' => false,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $data = $event->getData();
                // phpstan check
                if (!is_array($data)) {
                    return;
                }
                $data['payum.http_client'] = '@payplug_sylius_payplug_plugin.api_client.payplug';
                $event->setData($data);
            })
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [PayPlugGatewayConfigurationType::class];
    }
}
