<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PayPlugGatewayConfigurationTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Test\Traits\ValidatorExtensionTrait;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression coverage for the "conditional-required errors are silently discarded" bug: the
 * old PRE_SUBMIT listener added FormErrors to the hfIdentifier/hfSubMerchantId children, but
 * Form::submit() resets every form's $errors to [] at the very start of ITS OWN submission, and
 * children submit immediately after the parent's PRE_SUBMIT fires - wiping out any error added
 * to a child during the parent's PRE_SUBMIT before submission finishes.
 *
 * This test exercises the real Symfony form lifecycle (via TypeTestCase, no mocked
 * FormBuilderInterface) end-to-end, including a realistic 3-level parent chain
 * (root -> gatewayConfig -> config), because the extended type's own inherited
 * AbstractGatewayConfigurationType::buildForm() PRE_SUBMIT listener walks
 * getParent()->getParent() to reach the payment method entity and its "channels" field.
 */
final class PayPlugGatewayConfigurationTypeExtensionFormSubmissionTest extends TypeTestCase
{
    use ValidatorExtensionTrait;

    private const ACCOUNT_ID_ERROR = 'payplug_sylius_payplug_plugin.form.account_id_required';

    private const SUBMERCHANT_ID_ERROR = 'payplug_sylius_payplug_plugin.form.submerchant_id_required';

    protected function getTypes(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $gatewayConfigRepository = $this->createMock(RepositoryInterface::class);
        $gatewayConfigRepository->method('findOneBy')->willReturn(null);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [
            new PayPlugGatewayConfigurationType($translator, $gatewayConfigRepository, $requestStack),
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

    public function testSubmit_hostedFieldsModeWithBothFieldsBlank_isInvalidWithBothErrors(): void
    {
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => '',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => '',
                ],
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid(), 'Form must be invalid when hosted_fields is selected but both fields are blank.');

        $configForm = $form->get('gatewayConfig')->get('config');

        $identifierErrors = $configForm->get(PayPlugGatewayFactory::HF_IDENTIFIER)->getErrors();
        self::assertCount(1, $identifierErrors);
        self::assertSame(self::ACCOUNT_ID_ERROR, $identifierErrors[0]->getMessage());

        $subMerchantErrors = $configForm->get(PayPlugGatewayFactory::HF_SUB_MERCHANT_ID)->getErrors();
        self::assertCount(1, $subMerchantErrors);
        self::assertSame(self::SUBMERCHANT_ID_ERROR, $subMerchantErrors[0]->getMessage());
    }

    public function testSubmit_hostedFieldsModeWithBothFieldsFilled_isValid(): void
    {
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_456',
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
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_456',
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
        // affected by blank identifier/sub-merchant fields.
        $form = $this->createRootForm();

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
                    PayPlugGatewayFactory::HF_IDENTIFIER => '',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => '',
                ],
            ],
        ]);

        self::assertTrue($form->isValid());
    }

    /**
     * `hfSubMerchantId` is a `PasswordType` field kept at Symfony's default `always_empty` (never
     * echoes the stored secret back into the rendered `value` attribute, unlike the earlier
     * `always_empty => false` this replaces). Leaving it blank on an already-configured payment
     * method must be read as "unchanged", not "clear it" - the same convention as a
     * change-password form - otherwise every edit that doesn't retype the SubMerchant ID would
     * silently wipe it.
     */
    public function testSubmit_hostedFieldsModeWithBlankSubMerchantIdAndPreviousValueExists_preservesPreviousValue(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');
        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_456',
        ]);

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => '',
                ],
            ],
        ]);

        self::assertTrue($form->isValid(), 'Leaving SubMerchant ID blank on an already-configured payment method must not be treated as missing.');
        self::assertSame(
            'sub_456',
            $configForm->getData()[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null,
        );
    }

    /**
     * The blank-preserves-previous-value convention above must not prevent an admin from actually
     * changing the SubMerchant ID - only a blank submission falls back to the previous value.
     */
    public function testSubmit_hostedFieldsModeWithNewSubMerchantIdValue_overwritesPreviousValue(): void
    {
        $form = $this->createRootForm();
        $configForm = $form->get('gatewayConfig')->get('config');
        $configForm->setData([
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_456',
        ]);

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_789_new',
                ],
            ],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame(
            'sub_789_new',
            $configForm->getData()[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null,
        );
    }

    /**
     * PRE-3553: selecting a non-EUR channel while `integrated_payment` is selected must be
     * rejected with a message specific to this feature ("...not compatible with Integrated
     * Payment"), not the generic per-gateway `base_currency_not_euro` wording every other
     * PayPlug-family gateway subtype still uses (Bancontact, American Express, Scalapay...).
     */
    public function testSubmit_integratedPaymentModeWithNonEurChannel_isInvalidWithCurrencyIncompatibleMessage(): void
    {
        $form = $this->createRootForm($this->buildChannels(['USD']));

        // clearMissing=false: "channels" isn't part of this submitted payload (only
        // gatewayConfig.config is), and the default clearMissing=true would otherwise call
        // submit(null) on it regardless - wiping the Collection set via createRootForm() before
        // the currency-check listener ever runs, even though it's not disabled.
        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
                    PayPlugGatewayFactory::HF_IDENTIFIER => '',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => '',
                ],
            ],
        ], false);

        self::assertFalse($form->isValid(), 'Form must be invalid when integrated_payment is selected but an associated channel is not EUR.');

        // The error is added to the specific channel's own child sub-form (mirroring the real
        // `channels` field being `multiple => true, expanded => true`, one child per channel),
        // not directly to the "channels" form itself.
        $channelErrors = $form->get('channels')->get('0')->getErrors();
        self::assertCount(1, $channelErrors);
        self::assertSame(
            'payplug_sylius_payplug_plugin.form.integrated_payment_currency_incompatible',
            $channelErrors[0]->getMessage(),
        );
    }

    /**
     * The same non-EUR channel must NOT be rejected for hosted_fields or redirected mode — only
     * integrated_payment requires every associated channel to be EUR.
     */
    public function testSubmit_hostedFieldsModeWithNonEurChannel_isValid(): void
    {
        $form = $this->createRootForm($this->buildChannels(['USD']));

        $form->submit([
            'gatewayConfig' => [
                'config' => [
                    PayPlugGatewayFactory::ONE_CLICK => false,
                    PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
                    PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                    PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
                    PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sub_456',
                ],
            ],
        ], false);

        self::assertTrue($form->isValid());
    }

    /**
     * @param list<string> $currencyCodes
     *
     * @return ArrayCollection<int, ChannelInterface>
     */
    private function buildChannels(array $currencyCodes): ArrayCollection
    {
        $channels = [];
        foreach ($currencyCodes as $index => $currencyCode) {
            $currency = $this->createMock(CurrencyInterface::class);
            $currency->method('getCode')->willReturn($currencyCode);

            /** @var ChannelInterface&MockObject $channel */
            $channel = $this->createMock(ChannelInterface::class);
            $channel->method('getCode')->willReturn('channel_' . $index);
            $channel->method('getBaseCurrency')->willReturn($currency);

            $channels[] = $channel;
        }

        return new ArrayCollection($channels);
    }

    /**
     * Builds a minimal but realistic 3-level tree: root (the PaymentMethod form, exposing
     * "channels") -> gatewayConfig -> config (PayPlugGatewayConfigurationType, the type under
     * test). This mirrors production nesting closely enough to exercise
     * AbstractGatewayConfigurationType's inherited PRE_SUBMIT listener (which the extended type
     * still carries) without it fatal-erroring on missing parents.
     *
     * @param ArrayCollection<int, ChannelInterface>|null $channels Real channel data for the
     *                                                              "channels" field, needed by
     *                                                              tests exercising the currency
     *                                                              check. Left null (an unset,
     *                                                              non-Collection field) for tests
     *                                                              that don't care about it.
     */
    private function createRootForm(?ArrayCollection $channels = null): \Symfony\Component\Form\FormInterface
    {
        $paymentMethod = new class() {
            public function getId(): ?int
            {
                // Non-null so AbstractGatewayConfigurationType::checkCreationRequirements()
                // short-circuits without needing a configured gatewayConfigRepository.
                return 1;
            }
        };

        $root = $this->factory->createBuilder(FormType::class, $paymentMethod, ['data_class' => null]);
        if (null !== $channels) {
            // A bare FormType (no data_class) round-trips setData()/getData() untouched - unlike
            // TextType, it has no model-to-view transformer that would choke on a Collection. It
            // needs one child per channel, named by its collection key, because the production
            // currency-check listener does `$formChannels->get((string) $key)->addError(...)` -
            // mirroring the real `channels` field being a `multiple => true, expanded => true`
            // ChoiceType, which creates one child sub-form per choice - and, critically, sets
            // `error_bubbling => false` on those children (ChoiceType.php), unlike a bare
            // FormType's default of bubbling errors up to its parent when compound. Without this,
            // addError() on a channel's sub-form bubbles all the way to the root instead of
            // staying on that sub-form - purely a test-double mismatch, not a production concern.
            // NOTE: this field is NOT `disabled => true` - Form::isValid() unconditionally returns
            // true for a disabled form regardless of its errors, and Form::getErrors(true) skips
            // any child that isSubmitted() && isValid() when aggregating - together those two
            // rules mean a disabled "channels" would make the whole root form always report valid
            // no matter what error is added deep inside it. Its pre-set data survives submission
            // instead via `$form->submit($data, false)` (clearMissing=false) at the call site,
            // which is not disabled but also isn't reset by an absent key.
            $channelsBuilder = $root->create('channels', FormType::class, [
                'mapped' => false,
                'data_class' => null,
            ]);
            foreach ($channels as $key => $channel) {
                $channelsBuilder->add((string) $key, FormType::class, [
                    'mapped' => false,
                    'data_class' => null,
                    'error_bubbling' => false,
                ]);
            }
            $root->add($channelsBuilder);
        } else {
            $root->add('channels', TextType::class, ['mapped' => false]);
        }

        $gatewayConfig = $root->create('gatewayConfig', FormType::class, ['mapped' => false]);
        $gatewayConfig->add('config', PayPlugGatewayConfigurationType::class);

        $root->add($gatewayConfig);

        $form = $root->getForm();
        if (null !== $channels) {
            // Force the root's own lazy defaultDataSet initialization (and its mapDataToForms
            // cascade, which would otherwise reset the unmapped "channels" field to null the
            // first time anything touches this form) to run now, BEFORE setting "channels"'s
            // real data below - so our setData() call is the last word, not overwritten by it.
            $form->getData();
            $form->get('channels')->setData($channels);
        }

        return $form;
    }
}
