<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventSubscriber;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\KernelEvent;

final class DisplayOneyGatewayFormEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RepositoryInterface $paymentMethodRepository,
        private OneyCheckerInterface $oneyChecker,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.controller' => 'handle',
        ];
    }

    public function handle(KernelEvent $event): void
    {
        if ('sylius_admin_payment_method_update' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\PaymentMethod|null $subject */
        $subject = $this->paymentMethodRepository->find($event->getRequest()->attributes->get('id'));
        if (null === $subject) {
            return;
        }

        if (
            false === $subject->isEnabled() ||
            null === $subject->getGatewayConfig() ||
            OneyGatewayFactory::FACTORY_NAME !== $subject->getGatewayConfig()->getFactoryName()
        ) {
            return;
        }

        if ($this->oneyChecker->isEnabled()) {
            // Oney still enabled, do nothing
            return;
        }

        $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.oney_not_enabled');
        $subject->disable();
        $this->paymentMethodRepository->add($subject);
    }
}
