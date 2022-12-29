<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractGatewayConfigurationType extends AbstractType
{
    /** @var TranslatorInterface */
    protected $translator;

    protected RequestStack $requestStack;

    /** @var RepositoryInterface */
    private $gatewayConfigRepository;

    public function __construct(
        TranslatorInterface $translator,
        RepositoryInterface $gatewayConfigRepository,
        RequestStack $requestStack
    ) {
        $this->translator = $translator;
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->requestStack = $requestStack;
    }

    protected function canBeCreated(string $factoryName): bool
    {
        $alreadyExists = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

        return !$alreadyExists instanceof GatewayConfigInterface;
    }

    protected function checkCreationRequirements(
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
