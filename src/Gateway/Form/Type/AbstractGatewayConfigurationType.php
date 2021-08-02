<?php

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AbstractGatewayConfigurationType extends AbstractType
{
    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    protected $translator;

    /** @var FlashBagInterface */
    protected $flashBag;
    /**
     * @var \Sylius\Component\Resource\Repository\RepositoryInterface
     */
    private $gatewayConfigRepository;

    public function __construct(
        TranslatorInterface $translator,
        FlashBagInterface $flashBag,
        RepositoryInterface $gatewayConfigRepository
    ) {
        $this->translator = $translator;
        $this->flashBag = $flashBag;
        $this->gatewayConfigRepository = $gatewayConfigRepository;
    }

    protected function canBeCreated(string $factoryName): bool
    {
        $alreadyExists = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

        if (!$alreadyExists instanceof GatewayConfigInterface) {
            return true;
        }

        return false;
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
        /** @phpstan-ignore-next-line */
        $form->getParent()->getParent()->get('enabled')->addError(new FormError($message));
    }
}
