<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The per-channel gateway rules that need the *whole* payment method, not just its gateway config.
 *
 * Two listeners, one rule, two moments:
 *
 * - POST_SET_DATA guards the picker, rendering the channels another enabled gateway of the same
 *   factory already holds as unselectable (PRE-3629) — see `disableClaimedChannelChoices()`.
 * - POST_SUBMIT enforces the rule, turning a channel conflict or a base-currency violation into a
 *   form error (PRE-3628) — see `addChannelConflictErrors()` and `addBaseCurrencyErrors()`.
 *
 * Both sit on the *root* payment-method form. Sylius adds `channels` from `CoreBundle`'s own type
 * extension, i.e. after `gatewayConfig` (`PaymentMethodType` adds `enabled` then `gatewayConfig`);
 * since children are submitted in insertion order, a listener inside
 * `paymentMethod.gatewayConfig.config` runs before `channels` has been submitted and can only see
 * the persisted channel set. POST_SUBMIT on the root form is the first point where the submitted
 * channel set, the submitted `enabled` flag and the mapped gateway config all exist; the methods
 * above document why POST_SET_DATA is the matching point on the render side.
 */
final class PaymentMethodTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private GatewayChannelConflictChecker $conflictChecker,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            $paymentMethod = $this->resolvePayPlugPaymentMethod($event->getData());

            if (null === $paymentMethod || null === $this->resolveConfigurationType($form)) {
                return;
            }

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            $this->disableClaimedChannelChoices($form, $paymentMethod, (string) $gatewayConfig->getFactoryName());
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $configurationType = $this->resolveConfigurationType($form);
            $paymentMethod = $this->resolvePayPlugPaymentMethod($event->getData());

            if (null === $configurationType || null === $paymentMethod) {
                return;
            }

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            $this->addChannelConflictErrors($form, $paymentMethod, (string) $gatewayConfig->getFactoryName());
            $this->addBaseCurrencyErrors($form, $paymentMethod, $gatewayConfig, $configurationType);
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [PaymentMethodType::class];
    }

    /**
     * Returns the submitted payment method only when it carries a gateway config with a factory
     * name; the listener pairs this with `resolveConfigurationType()` to decide whether the
     * payment method is one of ours.
     */
    private function resolvePayPlugPaymentMethod(mixed $data): ?PaymentMethodInterface
    {
        if (!$data instanceof PaymentMethodInterface) {
            return null;
        }

        $gatewayConfig = $data->getGatewayConfig();

        $isPayPlugPaymentMethod = $gatewayConfig instanceof GatewayConfigInterface &&
            null !== $gatewayConfig->getFactoryName();

        return $isPayPlugPaymentMethod ? $data : null;
    }

    /**
     * Resolving to non-null is what makes a submitted payment method "one of ours": the decision
     * rests on the gateway configuration form's own type rather than on a hardcoded factory-name
     * list, since every PayPlug gateway configuration type extends `AbstractGatewayConfigurationType`
     * and nothing else does, so the test stays exact when an eighth gateway is added.
     *
     * `GatewayConfigType` only adds the `config` child when the factory has a registered
     * configuration type, hence the `has()` guards.
     *
     * The per-gateway currency policy is read back off the configuration type instance rather than
     * duplicated into a registry here: it already lives one-class-per-gateway, and only the CB type
     * narrows it (to Integrated Payment). Form types are stateless services, so calling their
     * public hooks is safe.
     */
    private function resolveConfigurationType(FormInterface $form): ?AbstractGatewayConfigurationType
    {
        if (!$form->has('gatewayConfig') || !$form->get('gatewayConfig')->has('config')) {
            return null;
        }

        $configurationType = $form->get('gatewayConfig')->get('config')->getConfig()->getType()->getInnerType();

        return $configurationType instanceof AbstractGatewayConfigurationType ? $configurationType : null;
    }

    /**
     * PRE-3629: render the channels another enabled gateway of the same factory already holds as
     * unselectable, instead of letting the admin pick one only to be refused on submit.
     *
     * The field belongs to Sylius' own `CoreBundle` type extension, so it cannot be configured from
     * our `buildForm()` — extension ordering between the two is not guaranteed. POST_SET_DATA on the
     * root form is the first safe point: `GatewayConfigType` adds its `config` child during its own
     * PRE_SET_DATA, so at root PRE_SET_DATA `resolveConfigurationType()` would still find nothing,
     * whereas by root POST_SET_DATA every child is built and populated. Replacing a child there is
     * also still data-mapped (`Form::add()` only skips mapping while `lockSetData` is on, i.e.
     * during PRE_SET_DATA).
     *
     * A resolved form config cannot be mutated, hence the replace-with-copied-options approach.
     * `data` is `setDefined` rather than defaulted on `FormType`, so it is absent from the copied
     * options unless someone deliberately set it — in which case carrying it over is the correct
     * thing to do anyway.
     */
    private function disableClaimedChannelChoices(
        FormInterface $form,
        PaymentMethodInterface $paymentMethod,
        string $factoryName,
    ): void {
        if (!$form->has('channels')) {
            return;
        }

        $claimedChannels = $this->conflictChecker->findClaimedChannels($paymentMethod, $factoryName);

        if ([] === $claimedChannels) {
            return;
        }

        $form->add('channels', ChannelChoiceType::class, \array_merge(
            $form->get('channels')->getConfig()->getOptions(),
            [
                'choice_attr' => fn (ChannelInterface $channel): array => $this->channelChoiceAttributes(
                    $channel,
                    $claimedChannels,
                ),
            ],
        ));
    }

    /**
     * A channel the payment method being edited already holds is never reported as claimed (see
     * `GatewayChannelConflictChecker::findClaimedChannels()`), and that is deliberate: browsers do
     * not submit disabled checkboxes, so disabling a *checked* one would silently drop the channel
     * on save. Those are left selectable and reported by the POST_SUBMIT rule instead.
     *
     * `disabled => true` is rendered as `disabled="disabled"` by form_div_layout's `attributes`
     * block, which every Sylius form theme inherits. That block also pipes `title` through `|trans`
     * against the *field's* translation domain — harmless here, since re-translating an already
     * translated sentence finds no catalogue entry and Symfony hands the string straight back. The
     * message can't be left as a bare key for Twig to translate instead: `attr_translation_parameters`
     * is set per field, not per choice, and each channel has to name a different payment method.
     *
     * @param array<string, PaymentMethodInterface> $claimedChannels channel code => claiming method
     *
     * @return array<string, mixed>
     */
    private function channelChoiceAttributes(ChannelInterface $channel, array $claimedChannels): array
    {
        $channelCode = (string) $channel->getCode();
        $claimedBy = $claimedChannels[$channelCode] ?? null;

        if (null === $claimedBy) {
            return [];
        }

        return [
            'disabled' => true,
            'title' => $this->translator->trans(
                'payplug_sylius_payplug_plugin.form.gateway_channel_conflict',
                [
                    '%channel%' => $channelCode,
                    '%payment_method%' => (string) $claimedBy->getName(),
                ],
            ),
        ];
    }

    private function addChannelConflictErrors(
        FormInterface $form,
        PaymentMethodInterface $paymentMethod,
        string $factoryName,
    ): void {
        if (!$form->has('channels')) {
            return;
        }

        $seenMessages = [];
        foreach ($this->conflictChecker->findConflicts($paymentMethod, $factoryName) as $conflict) {
            $message = $this->translator->trans(
                'payplug_sylius_payplug_plugin.form.gateway_channel_conflict',
                [
                    '%channel%' => (string) $conflict['channel']->getCode(),
                    '%payment_method%' => (string) $conflict['paymentMethod']->getName(),
                ],
            );

            if (\in_array($message, $seenMessages, true)) {
                continue;
            }

            $seenMessages[] = $message;
            $form->get('channels')->addError(new FormError($message));
        }
    }

    private function addBaseCurrencyErrors(
        FormInterface $form,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig,
        AbstractGatewayConfigurationType $configurationType,
    ): void {
        if (
            !$form->has('channels') ||
            !$configurationType->shouldValidateBaseCurrency($gatewayConfig->getConfig())
        ) {
            return;
        }

        $seenMessages = [];
        foreach ($paymentMethod->getChannels() as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }

            $baseCurrency = $channel->getBaseCurrency();

            if (null === $baseCurrency || $configurationType->getBaseCurrencyCode() === $baseCurrency->getCode()) {
                continue;
            }

            $message = $configurationType->baseCurrencyViolationMessage($channel);

            if (\in_array($message, $seenMessages, true)) {
                continue;
            }

            $seenMessages[] = $message;
            $form->get('channels')->addError(new FormError($message));
        }
    }
}
