<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\PaymentType;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class PaymentTypeExtension extends AbstractTypeExtension
{
    /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface */
    private $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
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
            ])->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                /** @var \Sylius\Component\Core\Model\PaymentMethod $paymentMethod */
                $paymentMethod = $event->getForm()->get('method')->getData();

                if (!$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface) {
                    return;
                }

                if (OneyGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName() ||
                    false === $event->getForm()->has('oney_payment_choice')) {
                    return;
                }

                /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
                $payment = $event->getData();
                $order = $payment->getOrder();
                if (!$order instanceof OrderInterface) {
                    return;
                }

                $shippingAddress = $order->getShippingAddress();
                if (!$shippingAddress instanceof AddressInterface) {
                    return;
                }

                // TODO : Ref US 1.14.1 validate shipment data for mandatory fields
                if ($shippingAddress->getCompany() === null) {
                    $event->getForm()->addError(new FormError('Oney est disponible que quand la companie est remplie dans l\'adresse.'));

                    return;
                }

                $data = $event->getForm()->get('oney_payment_choice')->getData();
                $this->session->set('oney_payment_method', $data);
            });
    }

    public static function getExtendedTypes(): array
    {
        return [PaymentType::class];
    }
}
