<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyOrderChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PaymentTypeExtension extends AbstractTypeExtension
{
    /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface */
    private $session;

    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    private $translator;

    /** @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyOrderChecker */
    private $orderChecker;

    public function __construct(
        SessionInterface $session,
        TranslatorInterface $translator,
        OneyOrderChecker $orderChecker
    ) {
        $this->session = $session;
        $this->translator = $translator;
        $this->orderChecker = $orderChecker;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('oney_payment_choice', ChoiceType::class, [
                'mapped' => false,
                'choices' => [
                    '3x' => 'oney_x3_with_fees',
                    '4x' => 'oney_x4_with_fees',
                ],
            ])
            ->add('payplug_card_choice', TextType::class, [
                'mapped' => false,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, function (): void {
                // Remove on preset data, it'll be readded if needed in post_submit
                $this->session->remove('oney_has_error');
                $this->session->remove('payplug_payment_method');
            })
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                if ($this->session->has('oney_payment_method')) {
                    $event->getForm()->get('oney_payment_choice')->setData($this->session->get('oney_payment_method'));
                }
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                /** @var \Sylius\Component\Core\Model\PaymentMethod|null $paymentMethod */
                $paymentMethod = $event->getForm()->get('method')->getData();

                if (null === $paymentMethod || !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface) {
                    return;
                }

                if (PayPlugGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()->getFactoryName() &&
                    false === $event->getForm()->has('payplug_card_choice')) {
                    return;
                }

                if (PayPlugGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()->getFactoryName()) {
                    $data = $event->getForm()->get('payplug_card_choice')->getData();
                    $this->session->set('payplug_payment_method', $data);

                    return;
                }

                if (OneyGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()->getFactoryName() ||
                    false === $event->getForm()->has('oney_payment_choice')) {
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
                        $this->translator->trans('payplug_sylius_payplug_plugin.form.oney_error')
                    );
                }
                // Possible other checks here

                if (\count($errors) > 0) {
                    \array_walk($errors, static function (FormError $error) use ($event): void {
                        $event->getForm()->addError($error);
                    });
                    $this->session->set('oney_has_error', true);

                    return;
                }

                $data = $event->getForm()->get('oney_payment_choice')->getData();
                $this->session->set('oney_payment_method', $data);
            })
        ;
    }

    public static function getExtendedTypes(): array
    {
        return [PaymentType::class];
    }
}
