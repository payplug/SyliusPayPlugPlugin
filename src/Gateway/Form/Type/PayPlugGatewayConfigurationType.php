<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Payplug\Exception\UnauthorizedException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

final class PayPlugGatewayConfigurationType extends AbstractType
{
    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('secretKey', TextType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.secret_key',
                'constraints' => [
                    new NotBlank([
                        'message' => 'payplug_sylius_payplug_plugin.secret_key.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.ui.retrieve_secret_key_in_api_configuration_portal'),
                'help_html' => true,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $data = $event->getData();

                $data['payum.http_client'] = '@payplug_sylius_payplug_plugin.api_client.payplug';

                $event->setData($data);
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                // This listener will validate payplug ApiKey.
                // @TODO remove it after merging projet-oney as there is now a Validator for this
                $data = $event->getData();

                try {
                    Assert::notEmpty($data['secretKey']);
                    \Payplug\Payplug::init(['secretKey' => $data['secretKey']]);
                    \Payplug\Authentication::getPermissions();
                } catch (UnauthorizedException $exception) {
                    $event->getForm()->get('secretKey')->addError(new FormError(
                        $this->translator->trans('payplug_sylius_payplug_plugin.secret_key.not_valid', [], 'validators')
                    ));
                } catch (\Throwable $exception) {
                    // Do nothing
                }
            })
        ;
    }
}
