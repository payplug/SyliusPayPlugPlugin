<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyOrderChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\OneySupportedPaymentChoiceProvider;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\PaymentType;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PaymentTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
        private OneyOrderChecker $orderChecker,
        private OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add('oney_payment_choice', ChoiceType::class, [
                'mapped' => false,
                'block_prefix' => 'oney_payment_choice',
                'choices' => $this->oneySupportedPaymentChoiceProvider->getSupportedPaymentChoices(true),
            ])
            ->add('payplug_card_choice', TextType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, function (): void {
                // Remove on preset data, it'll be readded if needed in post_submit
                $this->requestStack->getSession()->remove('oney_has_error');
                $this->requestStack->getSession()->remove('payplug_payment_method');
            })
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                if ($this->requestStack->getSession()->has('oney_payment_method')) {
                    $event->getForm()->get('oney_payment_choice')->setData($this->requestStack->getSession()->get('oney_payment_method'));
                }
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                /** @var \Sylius\Component\Core\Model\PaymentMethod|null $paymentMethod */
                $paymentMethod = $event->getForm()->get('method')->getData();

                if (null === $paymentMethod || !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface) {
                    return;
                }

                if (
                    PayPlugGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()->getFactoryName() &&
                    (false === $event->getForm()->has('payplug_card_choice') || null === $event->getForm()->get('payplug_card_choice')->getData())
                ) {
                    return;
                }

                if (PayPlugGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()->getFactoryName() && null !== $event->getForm()->get('payplug_card_choice')->getData()) {
                    $payplugCardId = $event->getForm()->get('payplug_card_choice')->getData();
                    $this->requestStack->getSession()->set('payplug_payment_method', $payplugCardId);

                    return;
                }

                if (
                    OneyGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName() ||
                    false === $event->getForm()->has('oney_payment_choice')
                ) {
                    return;
                }

                /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
                $payment = $event->getData();
                $order = $payment->getOrder();
                if (!$order instanceof OrderInterface) {
                    return;
                }

                $errors = [];
                if (!$this->orderChecker->isOrderInfoCorrect($order)) {
                    $errors[] = new FormError(
                        $this->translator->trans('payplug_sylius_payplug_plugin.form.oney_error'),
                    );
                }
                // Possible other checks here

                if ($errors !== []) {
                    \array_walk($errors, static function (FormError $error) use ($event): void {
                        $event->getForm()->get('method')->addError($error);
                    });
                    $this->requestStack->getSession()->set('oney_has_error', true);

                    return;
                }

                $data = $event->getForm()->get('oney_payment_choice')->getData();
                $this->requestStack->getSession()->set('oney_payment_method', $data);
            })
        ;
    }

    public static function getExtendedTypes(): array
    {
        return [PaymentType::class];
    }
}
