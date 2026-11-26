<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PproEligibilityTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\ScalapayWidgetTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PproCountryChoiceType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetSettings;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ScalapayWidgetTypeExtensionTest extends TypeTestCase
{
    private const CONNECTED = ['live' => true, 'live_client' => ['client_id' => 'id', 'client_secret' => 'secret']];

    private const PAYMENT_METHOD_BLOCK = [
        'name' => 'scalapay',
        'enabled' => true,
        'allowed_countries' => ['FR', 'IT'],
        'default_amount' => ['min' => 500, 'max' => 200000],
        'configured_amount' => ['min' => null, 'max' => null],
        'configured_countries' => null,
        'live' => true,
        'refreshed_at' => '2026-11-19T09:00:00+00:00',
        'merchant_token' => 'TESTTOKEN1',
    ];

    protected function getTypes(): array
    {
        return [
            new MoneyType(),
            new PproCountryChoiceType(),
            new ScalapayGatewayConfigurationType($this->translator()),
        ];
    }

    protected function getTypeExtensions(): array
    {
        return [new PproEligibilityTypeExtension(), new ScalapayWidgetTypeExtension($this->translator())];
    }

    public function testExtendsTheScalapayConfigTypeOnly(): void
    {
        self::assertSame([ScalapayGatewayConfigurationType::class], [...ScalapayWidgetTypeExtension::getExtendedTypes()]);
    }

    public function testAddsACheckboxAndASelectorFieldPerPage(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED);

        foreach (ScalapayWidgetSettings::PAGES as $page) {
            $enabled = $form->get('scalapay_widget_' . $page)->getConfig();
            self::assertInstanceOf(CheckboxType::class, $enabled->getType()->getInnerType());
            self::assertSame(sprintf('[scalapay_widget][%s][enabled]', $page), (string) $enabled->getPropertyPath());

            $selector = $form->get('scalapay_widget_' . $page . '_amount_selector')->getConfig();
            self::assertInstanceOf(TextType::class, $selector->getType()->getInnerType());
            self::assertSame(sprintf('[scalapay_widget][%s][amount_selector]', $page), (string) $selector->getPropertyPath());
        }
    }

    public function testEveryPageIsUncheckedWithItsDefaultSelectorWhenNothingIsStored(): void
    {
        $view = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED)->createView();

        foreach (ScalapayWidgetSettings::PAGES as $page) {
            self::assertFalse($view['scalapay_widget_' . $page]->vars['checked'], $page);
            self::assertSame(ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS[$page], $view['scalapay_widget_' . $page . '_amount_selector']->vars['value']);
        }
    }

    public function testPagesAreAlsoOffOnANewPaymentMethod(): void
    {
        $view = $this->factory->create(ScalapayGatewayConfigurationType::class)->createView();

        self::assertFalse($view['scalapay_widget_product']->vars['checked']);
    }

    public function testStoredSettingsAreShown(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED + ['scalapay_widget' => [
            'product' => ['enabled' => true, 'amount_selector' => '#my-price'],
        ]]);

        $view = $form->createView();

        self::assertTrue($view['scalapay_widget_product']->vars['checked']);
        self::assertSame('#my-price', $view['scalapay_widget_product_amount_selector']->vars['value']);
        self::assertFalse($view['scalapay_widget_cart']->vars['checked']);
    }

    public function testSubmittedSettingsAreStoredBesideTheUntouchedPayPlugBlock(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED + ['payment_method' => self::PAYMENT_METHOD_BLOCK]);

        $form->submit($this->submitted([
            'scalapay_widget_cart' => null,
            'scalapay_widget_cart_amount_selector' => '',
            'scalapay_widget_checkout_amount_selector' => '  #order-total  ',
        ]));

        self::assertTrue($form->isValid());
        $data = $form->getData();
        self::assertSame([
            'product' => ['enabled' => true, 'amount_selector' => '#price'],
            'cart' => ['enabled' => false, 'amount_selector' => null],
            'checkout' => ['enabled' => true, 'amount_selector' => '#order-total'],
        ], $data['scalapay_widget']);
        self::assertSame('TESTTOKEN1', $data['payment_method']['merchant_token']);
        self::assertSame(['min' => 500, 'max' => 200000], $data['payment_method']['default_amount']);
    }

    public function testAnInvalidSelectorIsRefused(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED);

        $form->submit($this->submitted(['scalapay_widget_product_amount_selector' => '"><script>alert(1)</script>']));

        self::assertFalse($form->isValid());
        self::assertSame(['payplug_sylius_payplug_plugin.scalapay_widget.invalid_amount_selector'], $this->errors($form->get('scalapay_widget_product_amount_selector')));
    }

    public function testAnInvalidSelectorIsRefusedEvenOnADisabledPage(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED);

        $form->submit($this->submitted(['scalapay_widget_cart' => null, 'scalapay_widget_cart_amount_selector' => 'a{color:red}']));

        self::assertSame(['payplug_sylius_payplug_plugin.scalapay_widget.invalid_amount_selector'], $this->errors($form->get('scalapay_widget_cart_amount_selector')));
    }

    public function testAnEnabledPageNeedsASelector(): void
    {
        $form = $this->factory->create(ScalapayGatewayConfigurationType::class, self::CONNECTED);

        $form->submit($this->submitted(['scalapay_widget_checkout_amount_selector' => '']));

        self::assertFalse($form->isValid());
        self::assertSame(['payplug_sylius_payplug_plugin.scalapay_widget.amount_selector_required'], $this->errors($form->get('scalapay_widget_checkout_amount_selector')));
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array<string, mixed>
     */
    private function submitted(array $overrides): array
    {
        $submitted = array_merge([
            'live' => '1',
            'ppro_countries' => ['FR', 'IT'],
            'scalapay_widget_product' => '1',
            'scalapay_widget_product_amount_selector' => '#price',
            'scalapay_widget_cart' => '1',
            'scalapay_widget_cart_amount_selector' => '#total',
            'scalapay_widget_checkout' => '1',
            'scalapay_widget_checkout_amount_selector' => '#order-total',
        ], $overrides);

        return array_filter($submitted, static fn ($value): bool => null !== $value);
    }

    /**
     * @return list<string>
     */
    private function errors(FormInterface $field): array
    {
        $messages = [];
        foreach ($field->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
