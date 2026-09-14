<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PaymentMethodTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormConfigInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Direct unit test of the POST_SUBMIT composition: per-gateway gate
 * (`shouldValidateBaseCurrency()`) + base-currency comparison + `FormError` emission on the
 * `channels` child.
 *
 * The hook tests in `PayPlugGatewayConfigurationTypeTest` pin the gate in isolation; this one pins
 * that the extension feeds it the array shape production actually produces — the **mapped** gateway
 * config off `GatewayConfigInterface::getConfig()`, whose display mode is persisted as the
 * `integratedPayment`/`hostedFields` booleans (see `PayPlugGatewayFactory::resolveDisplayModeFlags()`),
 * never as the unmapped `hostedFieldsMode` form field.
 *
 * This replaces the currency assertions that used to live in
 * `PayPlugGatewayConfigurationTypeExtensionFormSubmissionTest` against the old PRE_SUBMIT listener.
 */
final class PaymentMethodTypeExtensionTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id) => $id);
    }

    /**
     * PRE-3553: Integrated Payment is EUR-only, and the error must land on the `channels` field.
     */
    public function testPostSubmit_integratedPaymentOnNonEurChannel_addsCurrencyErrorOnChannels(): void
    {
        self::assertSame(
            ['payplug_sylius_payplug_plugin.form.integrated_payment_currency_incompatible'],
            $this->submitPayPlugPaymentMethod([PayPlugGatewayFactory::INTEGRATED_PAYMENT => true], 'USD'),
        );
    }

    public function testPostSubmit_integratedPaymentOnEuroChannel_addsNoError(): void
    {
        self::assertSame(
            [],
            $this->submitPayPlugPaymentMethod([PayPlugGatewayFactory::INTEGRATED_PAYMENT => true], 'EUR'),
        );
    }

    /**
     * The CB gate narrows the check to Integrated Payment: Hosted Fields works in any currency.
     */
    public function testPostSubmit_hostedFieldsOnNonEurChannel_addsNoError(): void
    {
        self::assertSame(
            [],
            $this->submitPayPlugPaymentMethod([PayPlugGatewayFactory::HOSTED_FIELDS => true], 'USD'),
        );
    }

    /**
     * This extension must target the base `PaymentMethodType`, not the AdminBundle subtype, so that
     * the AdminBundle form (which extends the base type) inherits the listener too.
     */
    public function testGetExtendedTypes_returnsPaymentMethodType(): void
    {
        self::assertSame([PaymentMethodType::class], PaymentMethodTypeExtension::getExtendedTypes());
    }

    /**
     * PRE-3629: a channel held by another *enabled* CB gateway renders as an unselectable choice,
     * with the same wording the POST_SUBMIT rule would have produced had it been submitted.
     */
    public function testPostSetData_channelClaimedByAnotherEnabledGateway_isRenderedDisabled(): void
    {
        $choiceAttr = $this->rebuiltChannelsOptions()['choice_attr'];

        self::assertSame(
            [
                'disabled' => true,
                'title' => 'payplug_sylius_payplug_plugin.form.gateway_channel_conflict|WEB_FR|CB 1',
            ],
            $choiceAttr($this->channel('WEB_FR')),
        );
    }

    public function testPostSetData_unclaimedChannel_staysSelectable(): void
    {
        $choiceAttr = $this->rebuiltChannelsOptions()['choice_attr'];

        self::assertSame([], $choiceAttr($this->channel('WEB_IT')));
    }

    /**
     * The field is replaced rather than configured in place, so everything Sylius put on it —
     * `multiple`, `expanded`, its label — has to survive the round trip.
     */
    public function testPostSetData_rebuiltChannelsField_keepsItsOriginalOptions(): void
    {
        $options = $this->rebuiltChannelsOptions();

        self::assertTrue($options['multiple']);
        self::assertTrue($options['expanded']);
        self::assertSame('sylius.form.payment_method.channels', $options['label']);
    }

    /**
     * Payment methods belonging to other plugins share this form; their channel picker is none of
     * our business.
     */
    public function testPostSetData_paymentMethodIsNotOneOfOurs_leavesTheChannelsFieldUntouched(): void
    {
        self::assertNull($this->runPostSetData(new \stdClass()));
    }

    /**
     * Runs the extension's POST_SET_DATA listener over a CB payment method, against a rival CB
     * gateway that is enabled and holds `WEB_FR`.
     *
     * @return array<string, mixed> the options handed to `add('channels', ...)`
     */
    private function rebuiltChannelsOptions(): array
    {
        $captured = $this->runPostSetData($this->payPlugPaymentMethod());

        self::assertIsArray($captured);
        self::assertSame('channels', $captured['name']);
        self::assertSame(ChannelChoiceType::class, $captured['type']);
        self::assertIsCallable($captured['options']['choice_attr']);

        return $captured['options'];
    }

    /**
     * @return array{name: string, type: string, options: array<string, mixed>}|null
     *         the `add()` call the listener made, or null when it left the form alone
     */
    private function runPostSetData(mixed $data): ?array
    {
        $rival = $this->createMock(PaymentMethodInterface::class);
        $rival->method('getId')->willReturn(7);
        $rival->method('isEnabled')->willReturn(true);
        $rival->method('getName')->willReturn('CB 1');
        $rival->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB_FR')]));

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findEnabledByGatewayName')->willReturn([$rival]);

        $captured = null;
        $form = $this->buildRootForm(
            $this->channelsForm(),
            function (string $name, string $type, array $options) use (&$captured): void {
                $captured = ['name' => $name, 'type' => $type, 'options' => $options];
            },
        );

        // Renders the parameters into the result so the assertions can pin *which* channel and
        // which rival payment method the tooltip names, not just that some message was translated.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => \implode(
                '|',
                [$id, ...\array_values($parameters)],
            ),
        );

        ($this->captureListener(FormEvents::POST_SET_DATA, $repository, $translator))(new FormEvent($form, $data));

        return $captured;
    }

    /**
     * Stand-in for the `channels` field Sylius' own CoreBundle extension added, carrying the
     * resolved options the listener has to copy forward.
     */
    private function channelsForm(): FormInterface
    {
        $formConfig = $this->createMock(FormConfigInterface::class);
        $formConfig->method('getOptions')->willReturn([
            'multiple' => true,
            'expanded' => true,
            'label' => 'sylius.form.payment_method.channels',
            'choice_attr' => null,
        ]);

        $channelsForm = $this->createMock(FormInterface::class);
        $channelsForm->method('getConfig')->willReturn($formConfig);

        return $channelsForm;
    }

    private function payPlugPaymentMethod(): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn([]);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getId')->willReturn(null);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection());

        return $paymentMethod;
    }

    private function channel(string $code): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        return $channel;
    }

    /**
     * Runs the extension's POST_SUBMIT listener over a CB payment method carrying a single channel.
     *
     * @param array<string, mixed> $mappedGatewayConfig as returned by GatewayConfigInterface::getConfig()
     *
     * @return list<string> messages of the FormErrors added to the `channels` child
     */
    private function submitPayPlugPaymentMethod(array $mappedGatewayConfig, string $baseCurrencyCode): array
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($baseCurrencyCode);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('channel_code');
        $channel->method('getBaseCurrency')->willReturn($currency);

        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn($mappedGatewayConfig);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection([$channel]));

        $errors = [];
        $channelsForm = $this->createMock(FormInterface::class);
        $channelsForm->method('addError')->willReturnCallback(
            function (FormError $error) use (&$errors, $channelsForm): FormInterface {
                $errors[] = $error->getMessage();

                return $channelsForm;
            },
        );

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findEnabledByGatewayName')->willReturn([]);

        ($this->captureListener(FormEvents::POST_SUBMIT, $repository))(
            new FormEvent($this->buildRootForm($channelsForm), $paymentMethod),
        );

        return $errors;
    }

    /**
     * Minimal stand-in for the `paymentMethod` → `gatewayConfig` → `config` form tree the extension
     * walks to resolve the gateway's configuration type.
     */
    private function buildRootForm(FormInterface $channelsForm, ?callable $onAdd = null): FormInterface
    {
        $resolvedType = $this->createMock(ResolvedFormTypeInterface::class);
        $resolvedType->method('getInnerType')->willReturn(new PayPlugGatewayConfigurationType($this->translator));

        $formConfig = $this->createMock(FormConfigInterface::class);
        $formConfig->method('getType')->willReturn($resolvedType);

        $configForm = $this->createMock(FormInterface::class);
        $configForm->method('getConfig')->willReturn($formConfig);

        $gatewayConfigForm = $this->createMock(FormInterface::class);
        $gatewayConfigForm->method('has')->willReturnCallback(static fn (string $name): bool => 'config' === $name);
        $gatewayConfigForm->method('get')->willReturn($configForm);

        $form = $this->createMock(FormInterface::class);
        $form->method('has')->willReturnCallback(
            static fn (string $name): bool => \in_array($name, ['gatewayConfig', 'channels'], true),
        );
        $form->method('get')->willReturnCallback(
            static fn (string $name): FormInterface => 'channels' === $name ? $channelsForm : $gatewayConfigForm,
        );
        $form->method('add')->willReturnCallback(
            function (string $name, string $type, array $options) use ($onAdd, $form): FormInterface {
                if (null !== $onAdd) {
                    $onAdd($name, $type, $options);
                }

                return $form;
            },
        );

        return $form;
    }

    private function captureListener(
        string $wantedEventName,
        PaymentMethodRepositoryInterface $repository,
        ?TranslatorInterface $translator = null,
    ): callable {
        $extension = new PaymentMethodTypeExtension(
            new GatewayChannelConflictChecker($repository),
            $translator ?? $this->translator,
            $this->createMock(RequestStack::class),
        );

        $listener = null;
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(
            function (string $eventName, callable $callback) use (
                $wantedEventName,
                &$listener,
                $builder,
            ): FormBuilderInterface {
                if ($wantedEventName === $eventName) {
                    $listener = $callback;
                }

                return $builder;
            },
        );

        $extension->buildForm($builder, []);

        self::assertIsCallable($listener);

        return $listener;
    }
}
