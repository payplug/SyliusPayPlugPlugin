<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventListener;

use Payplug\Authentication;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use function Symfony\Component\Translation\t;

#[AsEventListener(event: 'sylius.payment_method.post_create', method: 'onCreate')]
final class PostSavePaymentMethodEventListener
{
    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
    ) {
    }

    public function onCreate(ResourceControllerEvent $event): void
    {
        $paymentMethod = $event->getSubject();
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return;
        }

        // TODO: check if the paymentMethod is one belong to payplug

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            // Should never happen
            return;
        }

        $request->getSession()->set('payplug_sylius_oauth_payment_method_id', $paymentMethod->getId());
        $setupRedirection = $this->router->generate('payplug_sylius_admin_auth_setup_redirection', referenceType: RouterInterface::ABSOLUTE_URL);
        $oauthCallback = $this->router->generate('payplug_sylius_admin_auth_oauth_callback', referenceType: RouterInterface::ABSOLUTE_URL);

        /** @var string $payplugRedirectUrl */
        $payplugRedirectUrl = Authentication::getRegisterUrl($setupRedirection, $oauthCallback);

        $event->setResponse(new RedirectResponse($payplugRedirectUrl));
    }
}
