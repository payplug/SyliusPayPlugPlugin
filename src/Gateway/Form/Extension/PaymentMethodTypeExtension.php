<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validations that need the *whole* submitted payment method, not just its gateway config.
 *
 * Sylius adds `channels` to the payment-method form from `CoreBundle`'s own type extension, i.e.
 * after `gatewayConfig`; since children are submitted in insertion order, a listener inside
 * `paymentMethod.gatewayConfig.config` runs before `enabled` and `channels` have been submitted
 * and can only see persisted data. POST_SUBMIT on the root form is the first point where the
 * submitted channel set, the submitted `enabled` flag and the mapped gateway config all exist.
 */
final class PaymentMethodTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private GatewayChannelConflictChecker $conflictChecker,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $paymentMethod = $this->resolvePayPlugPaymentMethod($form, $event->getData());

            if (null === $paymentMethod) {
                return;
            }

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            $this->addChannelConflictErrors($form, $paymentMethod, (string) $gatewayConfig->getFactoryName());
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [PaymentMethodType::class];
    }

    /**
     * Returns the submitted payment method only when it is one of ours.
     *
     * "One of ours" is decided by the gateway configuration form's own type rather than by a
     * hardcoded factory-name list: every PayPlug gateway configuration type extends
     * `AbstractGatewayConfigurationType` and nothing else does, so the test stays exact when an
     * eighth gateway is added.
     */
    private function resolvePayPlugPaymentMethod(FormInterface $form, mixed $data): ?PaymentMethodInterface
    {
        if (!$data instanceof PaymentMethodInterface) {
            return null;
        }

        $gatewayConfig = $data->getGatewayConfig();

        $isPayPlugPaymentMethod = $gatewayConfig instanceof GatewayConfigInterface &&
            null !== $gatewayConfig->getFactoryName() &&
            $this->hasPayPlugConfigurationType($form);

        return $isPayPlugPaymentMethod ? $data : null;
    }

    /**
     * `GatewayConfigType` only adds the `config` child when the factory has a registered
     * configuration type, hence the `has()` guards.
     */
    private function hasPayPlugConfigurationType(FormInterface $form): bool
    {
        if (!$form->has('gatewayConfig') || !$form->get('gatewayConfig')->has('config')) {
            return false;
        }

        $configurationType = $form->get('gatewayConfig')->get('config')->getConfig()->getType()->getInnerType();

        return $configurationType instanceof AbstractGatewayConfigurationType;
    }

    private function addChannelConflictErrors(
        FormInterface $form,
        PaymentMethodInterface $paymentMethod,
        string $factoryName,
    ): void {
        if (!$form->has('channels')) {
            return;
        }

        $flashedMessages = [];
        foreach ($this->conflictChecker->findConflicts($paymentMethod, $factoryName) as $conflict) {
            $message = $this->translator->trans(
                'payplug_sylius_payplug_plugin.form.gateway_channel_conflict',
                [
                    '%channel%' => (string) $conflict['channel']->getCode(),
                    '%payment_method%' => (string) $conflict['paymentMethod']->getName(),
                ],
            );

            $form->get('channels')->addError(new FormError($message));

            if (!\in_array($message, $flashedMessages, true)) {
                $flashedMessages[] = $message;
                $this->flash($message);
            }
        }
    }

    private function flash(string $message): void
    {
        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }
    }
}
