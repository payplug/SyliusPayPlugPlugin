<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PproEligibilityTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AmericanExpressGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ApplePayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\BancontactGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\OneyGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PproCountryChoiceType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\WeroGatewayConfigurationType;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PproEligibilityTypeExtensionTest extends TypeTestCase
{
    private const CONNECTED = ['live' => true, 'live_client' => ['client_id' => 'id', 'client_secret' => 'secret']];

    private string $previousLocale;

    protected function setUp(): void
    {
        // The placeholders are formatted with the default locale: pin it so the expected values do
        // not depend on the locale of the machine running the tests.
        $this->previousLocale = \Locale::getDefault();
        \Locale::setDefault('en');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->previousLocale);

        parent::tearDown();
    }

    protected function getTypes(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return [
            new MoneyType(),
            new PproCountryChoiceType(),
            new BancontactGatewayConfigurationType($translator),
            new WeroGatewayConfigurationType($translator),
            new ScalapayGatewayConfigurationType($translator),
        ];
    }

    protected function getTypeExtensions(): array
    {
        return [new PproEligibilityTypeExtension()];
    }

    public function testExtendsTheThreePproConfigTypesOnly(): void
    {
        $extended = [...PproEligibilityTypeExtension::getExtendedTypes()];

        self::assertSame([
            BancontactGatewayConfigurationType::class,
            WeroGatewayConfigurationType::class,
            ScalapayGatewayConfigurationType::class,
        ], $extended);

        foreach ([OneyGatewayConfigurationType::class, PayPlugGatewayConfigurationType::class, ApplePayGatewayConfigurationType::class, AmericanExpressGatewayConfigurationType::class] as $type) {
            self::assertNotContains($type, $extended);
        }
    }

    /**
     * @dataProvider pproConfigTypes
     */
    public function testAddsTheThreeFieldsToEachPproConfigType(string $type): void
    {
        $form = $this->factory->create($type, self::CONNECTED);

        self::assertTrue($form->has('ppro_min_amount'));
        self::assertTrue($form->has('ppro_max_amount'));
        self::assertTrue($form->has('ppro_countries'));
    }

    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function pproConfigTypes(): iterable
    {
        yield 'bancontact' => [BancontactGatewayConfigurationType::class];
        yield 'wero' => [WeroGatewayConfigurationType::class];
        yield 'scalapay' => [ScalapayGatewayConfigurationType::class];
    }

    public function testAmountFieldsAreEuroMoneyWithPropertyPaths(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, self::CONNECTED);

        foreach (['ppro_min_amount' => 'min', 'ppro_max_amount' => 'max'] as $field => $side) {
            $config = $form->get($field)->getConfig();
            self::assertInstanceOf(MoneyType::class, $config->getType()->getInnerType());
            self::assertSame('EUR', $config->getOption('currency'));
            self::assertFalse($config->getOption('required'));
            self::assertSame('[payment_method][configured_amount][' . $side . ']', (string) $config->getPropertyPath());
        }
    }

    public function testAmountSubmittedInEurosIsStoredInCents(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        $form->submit(['live' => '1', 'ppro_min_amount' => '12.50', 'ppro_max_amount' => '1500', 'ppro_countries' => ['BE']]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame(1250, $data['payment_method']['configured_amount']['min']);
        self::assertSame(150000, $data['payment_method']['configured_amount']['max']);
        self::assertSame(['BE'], $data['payment_method']['configured_countries']);
        self::assertSame(['min' => 30, 'max' => 2000000], $data['payment_method']['default_amount']);
        self::assertSame(['BE', 'DE'], $data['payment_method']['allowed_countries']);
        self::assertSame(['client_id' => 'id', 'client_secret' => 'secret'], $data['live_client']);
    }

    public function testEmptyAmountIsStoredAsNull(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        $form->submit(['live' => '1', 'ppro_min_amount' => '', 'ppro_max_amount' => '', 'ppro_countries' => ['BE', 'DE']]);

        self::assertSame(['min' => null, 'max' => null], $form->getData()['payment_method']['configured_amount']);
    }

    public function testStoredCentsAreDisplayedInEuros(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block([
            'configured_amount' => ['min' => 1250, 'max' => 150000],
        ])));

        $view = $form->createView();

        self::assertSame('12.50', $view['ppro_min_amount']->vars['value']);
        self::assertSame('1500.00', $view['ppro_max_amount']->vars['value']);
    }

    /**
     * An empty field follows the PayPlug limit, which the field shows as its placeholder.
     */
    public function testAmountFieldsShowTheApiLimitsAsPlaceholders(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block([
            'configured_amount' => ['min' => null, 'max' => null],
        ])));

        $view = $form->createView();

        self::assertSame('', $view['ppro_min_amount']->vars['value']);
        self::assertSame('0.30', $view['ppro_min_amount']->vars['attr']['placeholder'] ?? null);
        self::assertSame('20000.00', $view['ppro_max_amount']->vars['attr']['placeholder'] ?? null);
    }

    public function testAmountFieldsHaveNoPlaceholderWithoutApiLimits(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, self::CONNECTED);

        $view = $form->createView();

        self::assertArrayNotHasKey('placeholder', $view['ppro_min_amount']->vars['attr']);
        self::assertArrayNotHasKey('placeholder', $view['ppro_max_amount']->vars['attr']);
    }

    public function testCountryChoicesAreTheStoredAllowedCountries(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        self::assertSame(['BE', 'DE'], $this->choiceValues($form->get('ppro_countries')));
    }

    public function testCountryChoicesAreAllCountriesWhenAllowedIsNull(): void
    {
        $form = $this->factory->create(BancontactGatewayConfigurationType::class, $this->connectedWith($this->block(['allowed_countries' => null])));

        self::assertCount(\count(Countries::getCountryCodes()), $this->choiceValues($form->get('ppro_countries')));
    }

    public function testCountryChoicesAreAllCountriesWithoutAStoredBlock(): void
    {
        $form = $this->factory->create(BancontactGatewayConfigurationType::class, self::CONNECTED);

        self::assertCount(\count(Countries::getCountryCodes()), $this->choiceValues($form->get('ppro_countries')));
    }

    public function testAllChoicesSelectedMapsToNull(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block(['configured_countries' => ['BE']])));

        $form->submit(['live' => '1', 'ppro_countries' => ['DE', 'BE']]);

        self::assertNull($form->getData()['payment_method']['configured_countries']);
    }

    public function testSubsetIsStoredAsList(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        $form->submit(['live' => '1', 'ppro_countries' => ['DE']]);

        self::assertSame(['DE'], $form->getData()['payment_method']['configured_countries']);
    }

    public function testNoCountrySelectedIsStoredAsAnEmptyList(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        $form->submit(['live' => '1']);

        self::assertSame([], $form->getData()['payment_method']['configured_countries']);
    }

    public function testNullConfiguredCountriesRendersAllSelected(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block()));

        self::assertSame(['BE', 'DE'], $form->createView()['ppro_countries']->vars['value']);
    }

    public function testConfiguredCountriesRenderSelected(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, $this->connectedWith($this->block(['configured_countries' => ['DE']])));

        self::assertSame(['DE'], $form->createView()['ppro_countries']->vars['value']);
    }

    public function testFieldsAreDisabledBeforeTheConnection(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, []);

        self::assertTrue($form->get('ppro_min_amount')->isDisabled());
        self::assertTrue($form->get('ppro_max_amount')->isDisabled());
        self::assertTrue($form->get('ppro_countries')->isDisabled());

        $form->submit(['live' => '1', 'ppro_min_amount' => '12.50', 'ppro_countries' => ['FR']]);

        self::assertArrayNotHasKey('payment_method', $form->getData());
    }

    public function testFieldsAreEnabledOnceConnectedEvenWithoutAStoredBlock(): void
    {
        $form = $this->factory->create(WeroGatewayConfigurationType::class, ['test_client' => ['client_id' => 'id', 'client_secret' => 'secret']]);

        self::assertFalse($form->get('ppro_min_amount')->isDisabled());
        self::assertFalse($form->get('ppro_countries')->isDisabled());
    }

    /**
     * @return list<string>
     */
    private function choiceValues(FormInterface $field): array
    {
        return array_values(array_map(
            static fn ($choiceView): string => $choiceView->value,
            $field->createView()->vars['choices'],
        ));
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private function connectedWith(array $block): array
    {
        return self::CONNECTED + ['payment_method' => $block];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'name' => 'wero',
            'enabled' => true,
            'allowed_countries' => ['BE', 'DE'],
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => ['min' => 30, 'max' => 2000000],
            'configured_countries' => null,
        ], $overrides);
    }
}
