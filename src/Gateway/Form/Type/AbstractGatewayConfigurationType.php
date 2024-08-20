<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Doctrine\Common\Collections\Collection;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSavePaymentMethod;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsOneyEnabled;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsPayPlugSecretKeyValid;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractGatewayConfigurationType extends AbstractType
{
    public const VALIDATION_GROUPS = ['Default', 'sylius'];

    protected TranslatorInterface $translator;

    protected RequestStack $requestStack;

    protected string $noTestKeyMessage = '';

    protected string $noAccessMessage = '';

    protected string $gatewayFactoryTitle = '';

    protected string $gatewayFactoryName = '';

    protected string $gatewayBaseCurrencyCode = 'EUR';

    private RepositoryInterface $gatewayConfigRepository;

    public function __construct(
        TranslatorInterface $translator,
        RepositoryInterface $gatewayConfigRepository,
        RequestStack $requestStack
    ) {
        $this->translator = $translator;
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->requestStack = $requestStack;
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('secretKey', PasswordType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.secret_key',
                'validation_groups' => self::VALIDATION_GROUPS,
                'constraints' => [
                    new NotBlank([
                        'message' => 'payplug_sylius_payplug_plugin.secret_key.not_blank',
                    ]),
                    new IsPayPlugSecretKeyValid(),
                    new IsCanSavePaymentMethod([
                        'noTestKeyMessage' => $this->noTestKeyMessage,
                        'noAccessMessage' => $this->noAccessMessage,
                    ]),
                    new IsOneyEnabled(),
                ],
                'help' => $this->translator->trans('payplug_sylius_payplug_plugin.ui.retrieve_secret_key_in_api_configuration_portal'),
                'help_html' => true,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $this->checkCreationRequirements(
                    $this->gatewayFactoryTitle,
                    $this->gatewayFactoryName,
                    $event->getForm()
                );

                /** @phpstan-ignore-next-line */
                $formChannels = $event->getForm()->getParent()->getParent()->get('channels');
                $dataFormChannels = $formChannels->getData();
                if (!$dataFormChannels instanceof Collection) {
                    return;
                }
                /** @var ChannelInterface $dataFormChannel */
                foreach ($dataFormChannels as $key => $dataFormChannel) {
                    $baseCurrency = $dataFormChannel->getBaseCurrency();
                    if (null === $baseCurrency) {
                        continue;
                    }
                    $baseCurrencyCode = $baseCurrency->getCode();
                    if ($this->gatewayBaseCurrencyCode !== $baseCurrencyCode) {
                        $message = $this->translator->trans(
                            'payplug_sylius_payplug_plugin.form.base_currency_not_euro',
                            [
                                '#channel_code#' => $dataFormChannel->getCode(),
                                '#payment_method#' => $this->gatewayFactoryTitle,
                            ]
                        );
                        $formChannels->get((string) $key)->addError(new FormError($message));
                        $this->requestStack->getSession()->getFlashBag()->add('error', $message);
                    }
                }
            })
        ;
    }

    private function canBeCreated(string $factoryName): bool
    {
        $alreadyExists = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

        return !$alreadyExists instanceof GatewayConfigInterface;
    }

    private function checkCreationRequirements(
        string $factoryTitle,
        string $factoryName,
        FormInterface $form
    ): void {
        /** @phpstan-ignore-next-line */
        $paymentMethod = $form->getParent()->getParent()->getData();

        if (null !== $paymentMethod->getId()) {
            return;
        }

        if ($this->canBeCreated($factoryName)) {
            return;
        }

        $message = $this->translator->trans('payplug_sylius_payplug_plugin.form.only_one_gateway_allowed', ['%gateway_title%' => $factoryTitle]);
        /* @phpstan-ignore-next-line */
        $form->getParent()->getParent()->get('enabled')->addError(new FormError($message));
    }
}
