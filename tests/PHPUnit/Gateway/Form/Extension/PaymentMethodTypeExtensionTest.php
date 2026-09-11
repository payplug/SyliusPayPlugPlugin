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

        ($this->capturePostSubmitListener())(new FormEvent($this->buildRootForm($channelsForm), $paymentMethod));

        return $errors;
    }

    /**
     * Minimal stand-in for the `paymentMethod` → `gatewayConfig` → `config` form tree the extension
     * walks to resolve the gateway's configuration type.
     */
    private function buildRootForm(FormInterface $channelsForm): FormInterface
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

        return $form;
    }

    private function capturePostSubmitListener(): callable
    {
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findEnabledByGatewayName')->willReturn([]);

        $extension = new PaymentMethodTypeExtension(
            new GatewayChannelConflictChecker($repository),
            $this->translator,
            $this->createMock(RequestStack::class),
        );

        $listener = null;
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(
            function (string $eventName, callable $callback) use (&$listener, $builder): FormBuilderInterface {
                if (FormEvents::POST_SUBMIT === $eventName) {
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
