<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventListener;

use PayPlug\SyliusPayPlugPlugin\Gateway\GatewayCredentials;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refreshes the stored PayPlug limits of a PPRO payment method when its admin edit page is
 * displayed. It runs before the controller so that the form built on this request already shows
 * the refreshed limits; a submit (POST) is never refreshed, so it is validated against the limits
 * that were displayed. A payment method not connected to PayPlug yet has no limits to fetch.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class PproConfigRefreshOnAdminDisplayListener
{
    private const ROUTE = 'sylius_admin_payment_method_update';

    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethod> $paymentMethodRepository
     */
    public function __construct(
        private RepositoryInterface $paymentMethodRepository,
        private PproConfigurationRefresherInterface $pproConfigurationRefresher,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod(Request::METHOD_GET) || self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $paymentMethod = $this->findPproPaymentMethod($request);
        if (null === $paymentMethod) {
            return;
        }

        $result = $this->pproConfigurationRefresher->refresh($paymentMethod, true);

        if (!$result->refreshed) {
            $this->addFlash($request, 'warning', 'payplug_sylius_payplug_plugin.ppro.refresh_failed');
        } elseif ($result->adjusted) {
            $this->addFlash($request, 'info', 'payplug_sylius_payplug_plugin.ppro.settings_adjusted');
        }
    }

    private function findPproPaymentMethod(Request $request): ?PaymentMethodInterface
    {
        $id = $request->attributes->get('id');
        if (!\is_scalar($id)) {
            return null;
        }

        $paymentMethod = $this->paymentMethodRepository->find($id);
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return null;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $isConnectedPpro = null !== $gatewayConfig &&
            PproPaymentMethods::isPpro($gatewayConfig->getFactoryName()) &&
            GatewayCredentials::isConnected($gatewayConfig->getConfig());

        return $isConnectedPpro ? $paymentMethod : null;
    }

    private function addFlash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
