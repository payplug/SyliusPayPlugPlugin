<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PayPlugGatewayConfigurationTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Test\Traits\ValidatorExtensionTrait;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression coverage for the "conditional-required errors are silently discarded" bug: the
 * old PRE_SUBMIT listener added FormErrors to the hfIdentifier child, but
 * Form::submit() resets every form's $errors to [] at the very start of ITS OWN submission, and
 * children submit immediately after the parent's PRE_SUBMIT fires - wiping out any error added
 * to a child during the parent's PRE_SUBMIT before submission finishes.
 *
 * This test exercises the real Symfony form lifecycle (via TypeTestCase, no mocked
 * FormBuilderInterface) end-to-end, including a realistic 3-level parent chain
 * (root -> gatewayConfig -> config).
 *
 * The base-currency-per-channel check that used to also be exercised here (via
 * AbstractGatewayConfigurationType's own PRE_SUBMIT listener) has moved to
 * PaymentMethodTypeExtension's POST_SUBMIT listener on the root PaymentMethodType form; it is
 * covered by AbstractGatewayConfigurationTypeTest and PayPlugGatewayConfigurationTypeTest's hook
 * tests instead, since this file's minimal 3-level tree doesn't wire up PaymentMethodTypeExtension.
 */
final class PayPlugGatewayConfigurationTypeExtensionFormSubmissionTest extends TypeTestCase
{
    use ValidatorExtensionTrait;

    private const ACCOUNT_ID_ERROR = 'payplug_sylius_payplug_plugin.form.account_id_required';

    protected function getTypes(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return [
            new PayPlugGatewayConfigurationType($translator),
        ];
    }

    protected function getTypeExtensions(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return [
            new PayPlugGatewayConfigurationTypeExtension($translator),
        ];
    }

    public function testSubmit_hostedFieldsModeWithBlankIdentifier_isInvalidWithAccountIdError(): void
    {
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => '',
                ],
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid(), 'Form must be invalid when hosted_fields is selected but the account id is blank.');

        $configForm = $form->get('gatewayConfig')->get('config');

        $identifierErrors = $configForm->get(PayPlugGatewayFactory::HF_IDENTIFIER)->getErrors();
        self::assertCount(1, $identifierErrors);
        self::assertSame(self::ACCOUNT_ID_ERROR, $identifierErrors[0]->getMessage());
    }

    /**
     * The account id is now the only hosted-fields requirement — the SubMerchant ID field it used
     * to be paired with is gone, so filling this one alone must be enough to save the form.
     */
    public function testSubmit_hostedFieldsModeWithIdentifierFilled_isValid(): void
    {
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                ],
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid());
    }

    /**
     * Regression coverage for "the radio reverts to redirected on reload": DISPLAY_MODE_FIELD is
     * `mapped => false`, and the PRE_SET_DATA listener that used to pre-select it there got its
     * setData() call silently overwritten by Symfony's own DataMapper::mapDataToForms(), which
     * resets every unmapped child back to its configured (null) default immediately after
     * PRE_SET_DATA dispatches, before POST_SET_DATA fires. Moving the pre-selection to
     * POST_SET_DATA fixes it, since nothing runs after that to reset it again.
     */
    public function testSetData_existingIntegratedPaymentConfig_preselectsIntegratedPaymentRadio(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');

        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => true,
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
        ]);

        self::assertSame(
            PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
            $configForm->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->getData(),
        );
    }

    public function testSetData_existingHostedFieldsConfig_preselectsHostedFieldsRadio(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');

        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
        ]);

        self::assertSame(
            PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            $configForm->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->getData(),
        );
    }

    public function testSetData_neitherFlagSet_leavesRadioUnselected(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');

        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
        ]);

        self::assertNull($configForm->get(PayPlugGatewayFactory::DISPLAY_MODE_FIELD)->getData());
    }

    public function testSubmit_integratedPaymentMode_withBlankFields_isValid(): void
    {
        // The conditional requirement only applies to hosted_fields; other modes must not be
        // affected by a blank identifier field.
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
                    PayPlugGatewayFactory::HF_IDENTIFIER => '',
                ],
            ],
        ]);

        self::assertTrue($form->isValid());
    }

    /**
     * A payment method configured before the SubMerchant ID field was removed still carries
     * `hfSubMerchantId` in its stored config. Re-saving it must not fail on the now-unknown key,
     * and the leftover value is simply ignored — GatewayCredentialsResolver no longer reads it.
     */
    public function testSubmit_hostedFieldsModeWithALeftoverSubMerchantIdInStoredConfig_isValid(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');
        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            'hfSubMerchantId' => 'sub_456',
        ]);

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                ],
            ],
        ]);

        self::assertTrue($form->isValid());
    }

    /**
     * Builds a minimal but realistic 3-level tree: root (the PaymentMethod form, exposing
     * "channels") -> gatewayConfig -> config (PayPlugGatewayConfigurationType, the type under
     * test).
     */
    private function createRootForm(): \Symfony\Component\Form\FormInterface
    {
        $paymentMethod = new class() {
            public function getId(): ?int
            {
                return 1;
            }
        };

        $root = $this->factory->createBuilder(FormType::class, $paymentMethod, ['data_class' => null]);
        $root->add('channels', TextType::class, ['mapped' => false]);

        $gatewayConfig = $root->create('gatewayConfig', FormType::class, ['mapped' => false]);
        $gatewayConfig->add('config', PayPlugGatewayConfigurationType::class);

        $root->add($gatewayConfig);

        return $root->getForm();
    }
}
