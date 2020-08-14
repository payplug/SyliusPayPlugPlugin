<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\PaymentType;
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

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('oney_payment_choice', ChoiceType::class, [
                'mapped' => false,
                'choices' => [
                    '3x' => 'oney_x3_with_fees',
                    '4x' => 'oney_x4_with_fees',
                ],
            ])->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
                /** @var \Sylius\Component\Core\Model\PaymentMethod $paymentMethod */
                $paymentMethod = $event->getForm()->get('method')->getData();

                if (OneyGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName() ||
                    false === $event->getForm()->has('oney_payment_choice')) {
                    return;
                }

                /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
                $payment = $event->getData();
                // TODO : Ref US 1.14.1 validate shipment data for mandatory fields
                if ($payment->getOrder()->getShippingAddress()->getCompany() === null) {
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
