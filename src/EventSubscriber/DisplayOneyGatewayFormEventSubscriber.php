<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

final class DisplayOneyGatewayFormEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface
     */
    private $flashBag;
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient
     */
    private $oneyClient;
    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    private $requestStack;

    public function __construct(
        FlashBagInterface $flashBag,
        PayPlugApiClient $oneyClient,
        EntityManagerInterface $entityManager,
        RequestStack $requestStack
    ) {
        $this->flashBag = $flashBag;
        $this->oneyClient = $oneyClient;
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.payment_method.initialize_update' => 'handle',
        ];
    }

    public function handle(ResourceControllerEvent $event): void
    {
        /** @var \Sylius\Component\Core\Model\PaymentMethod $subject */
        $subject = $event->getSubject();
        if (false === $subject->isEnabled() ||
            OneyGatewayFactory::FACTORY_NAME !== $subject->getGatewayConfig()->getFactoryName()) {
            return;
        }

        $permissions = $this->oneyClient->getPermissions();
        if (true !== $permissions['can_use_oney'] ?? false) {
            // Oney still active, do nothing
            return;
        }

        $this->flashBag->add('error', 'payplug_sylius_payplug_plugin.error.oney_not_enabled');
        $subject->disable();
        $this->entityManager->flush();

        $event->setResponse(new RedirectResponse($this->requestStack->getMasterRequest()->getRequestUri()));
    }
}
