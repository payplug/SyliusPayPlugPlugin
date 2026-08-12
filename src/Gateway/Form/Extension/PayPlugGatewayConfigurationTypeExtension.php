<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PayPlugGatewayConfigurationTypeExtension extends AbstractTypeExtension
{
    public function __construct(private TranslatorInterface $translator)
    {
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
                'help' => 'payplug_sylius_payplug_plugin.form.one_click_help',
                'help_html' => true,
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
            ->add(PayPlugGatewayFactory::DISPLAY_MODE_FIELD, ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'placeholder' => 'payplug_sylius_payplug_plugin.form.redirected_payment_enable',
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'choices' => [
                    'payplug_sylius_payplug_plugin.form.integrated_payment_enable' => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
                    'payplug_sylius_payplug_plugin.ui.hosted_fields_option' => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                ],
            ])
            ->add(PayPlugGatewayFactory::HF_IDENTIFIER, TextType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.hf_identifier_label',
                'required' => false,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
            ])
            ->add(PayPlugGatewayFactory::HF_SUB_MERCHANT_ID, PasswordType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.hf_sub_merchant_id_label',
                'required' => false,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $rawData = $event->getData();
                if (!is_array($rawData)) {
                    return;
                }

                $submitted = $rawData[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? '';
                if (!is_scalar($submitted) || '' !== trim((string) $submitted)) {
                    return;
                }

                // PasswordType keeps its default `always_empty` (never echoes the stored secret
                // back into the rendered `value` attribute), so a blank submission means "left
                // untouched", not "clear it" - same convention as a change-password form. Restore
                // the previously persisted value instead of letting a blank field wipe it out.
                $previousData = $event->getForm()->getData();
                $previousValue = is_array($previousData) ? ($previousData[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null) : null;
                if (is_string($previousValue) && '' !== $previousValue) {
                    $rawData[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] = $previousValue;
                    $event->setData($rawData);
                }
            })
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $data = $event->getData();
                // phpstan check
                if (!is_array($data)) {
                    return;
                }
                $data['payum.http_client'] = '@payplug_sylius_payplug_plugin.api_client.payplug';
                $event->setData($data);
            })
            // DISPLAY_MODE_FIELD's pre-selection must happen on POST_SET_DATA, not PRE_SET_DATA:
            // it's `mapped => false`, and Symfony's DataMapper::mapDataToForms() runs right after
            // PRE_SET_DATA dispatches (as part of the same parent setData() call), resetting every
            // unmapped child back to its configured (null) default — silently wiping out a
            // setData() call made from PRE_SET_DATA. POST_SET_DATA fires after that reset, so
            // nothing overwrites it afterward.
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    return;
                }

                $event->getForm()->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->setData(
                    PayPlugGatewayFactory::resolveDisplayMode($data),
                );
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $submittedData = [
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => $form->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->getData(),
                    PayPlugGatewayFactory::HF_IDENTIFIER => $form->get(PayPlugGatewayFactory::HF_IDENTIFIER)->getData(),
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => $form->get(PayPlugGatewayFactory::HF_SUB_MERCHANT_ID)->getData(),
                ];

                foreach (PayPlugGatewayFactory::missingHostedFieldsRequirements($submittedData) as $field) {
                    $messageKey = PayPlugGatewayFactory::HF_IDENTIFIER === $field
                        ? 'payplug_sylius_payplug_plugin.form.account_id_required'
                        : 'payplug_sylius_payplug_plugin.form.submerchant_id_required';

                    $form->get($field)->addError(new FormError($this->translator->trans($messageKey)));
                }
            })
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    return;
                }

                $displayMode = $event->getForm()->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->getData();
                $displayMode = is_string($displayMode) ? $displayMode : null;
                $event->setData(array_merge($data, PayPlugGatewayFactory::resolveDisplayModeFlags($displayMode)));
            })
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [PayPlugGatewayConfigurationType::class];
    }
}
