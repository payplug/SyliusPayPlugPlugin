<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Doctrine\Common\Collections\Collection;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractGatewayConfigurationType extends AbstractType
{
    public const VALIDATION_GROUPS = ['Default', 'sylius'];

    protected string $gatewayFactoryTitle = '';

    protected string $gatewayFactoryName = '';

    protected string $gatewayBaseCurrencyCode = PayPlugGatewayFactory::BASE_CURRENCY_CODE;

    public function __construct(
        protected TranslatorInterface $translator,
        private RepositoryInterface $gatewayConfigRepository,
        protected RequestStack $requestStack,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('live', CheckboxType::class, [
                'block_name' => 'payplug_checkbox',
                'label' => 'payplug_sylius_payplug_plugin.ui.live',
                'help' => 'payplug_sylius_payplug_plugin.ui.live_help',
                'help_html' => true,
                'required' => false,
            ])
            ->add('renew_oauth', CheckboxType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.renew_oauth',
                'help' => 'payplug_sylius_payplug_plugin.ui.renew_oauth_help',
                'help_html' => true,
                'mapped' => false,
                'required' => false,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $this->checkCreationRequirements(
                    $this->gatewayFactoryTitle,
                    $this->gatewayFactoryName,
                    $event->getForm(),
                );

                /** @phpstan-ignore-next-line */
                $formChannels = $event->getForm()->getParent()->getParent()->get('channels');
                $dataFormChannels = $formChannels->getData();
                if (!$dataFormChannels instanceof Collection) {
                    return;
                }

                $rawData = $event->getData();
                if (!\is_array($rawData) || !$this->shouldValidateBaseCurrency($rawData)) {
                    return;
                }

                $flashedMessages = [];
                /** @var ChannelInterface $dataFormChannel */
                foreach ($dataFormChannels as $key => $dataFormChannel) {
                    $baseCurrency = $dataFormChannel->getBaseCurrency();
                    if (null === $baseCurrency) {
                        continue;
                    }
                    $baseCurrencyCode = $baseCurrency->getCode();
                    if ($this->gatewayBaseCurrencyCode !== $baseCurrencyCode) {
                        $message = $this->baseCurrencyViolationMessage($dataFormChannel);
                        $formChannels->get((string) $key)->addError(new FormError($message));
                        if (!\in_array($message, $flashedMessages, true)) {
                            $flashedMessages[] = $message;
                            $this->requestStack->getSession()->getFlashBag()->add('error', $message);
                        }
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
        FormInterface $form,
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

    /**
     * Hook for subtypes to scope the base-currency-per-channel restriction below.
     * Default: always enforced, preserving today's behavior for every gateway that doesn't
     * override this (Bancontact, American Express, Scalapay, Wero, Oney...).
     *
     * @see baseCurrencyViolationMessage() Companion hook customizing the message this guards.
     *
     * @param array<int|string, mixed> $rawFormData Raw PRE_SUBMIT data of the gateway config form.
     */
    protected function shouldValidateBaseCurrency(array $rawFormData): bool
    {
        return true;
    }

    /**
     * Hook for subtypes to customize the currency-violation message. Default matches today's
     * generic wording, used by every gateway subtype that doesn't override it (Bancontact,
     * American Express, Scalapay, Wero, Oney...).
     *
     * @see shouldValidateBaseCurrency() Companion hook scoping when this message is used.
     */
    protected function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        return $this->translator->trans(
            'payplug_sylius_payplug_plugin.form.base_currency_not_euro',
            [
                '#channel_code#' => $channel->getCode(),
                '#payment_method#' => $this->gatewayFactoryTitle,
            ],
        );
    }
}
