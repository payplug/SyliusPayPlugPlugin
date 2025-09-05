<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventListener;

use Payplug\Authentication;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use function Symfony\Component\Translation\t;

#[AsEventListener(event: 'sylius.payment_method.post_create', method: 'onCreate')]
#[AsEventListener(event: 'sylius.payment_method.post_update', method: 'onUpdate')]
final class PostSavePaymentMethodEventListener
{
    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
        private PaymentMethodValidator $paymentMethodValidator,
        private LoggerInterface $logger,
    ) {
    }

    public function onCreate(ResourceControllerEvent $event): void
    {
        $paymentMethod = $event->getSubject();
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return;
        }

        $gateway = $paymentMethod->getGatewayConfig();
        if (null === $gateway || !\str_contains($gateway->getFactoryName(), 'payplug')) {
            // A new payment method has been created but that is not a payplug one, do nothing
            return;
        }

        $this->startOAuth($paymentMethod, $event);
    }

    public function onUpdate(ResourceControllerEvent $event): void
    {
        $paymentMethod = $event->getSubject();
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return;
        }
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $isRenewal = $request->request->all('sylius_admin_payment_method')['gatewayConfig']['config']['renew_oauth'] ?? false;
        $isRenewal = \filter_var($isRenewal, \FILTER_VALIDATE_BOOLEAN);
        if (true !== $isRenewal) {
            // No need to renew the oauth token, let's validate the payment method with already existing config
            $this->paymentMethodValidator->process($paymentMethod);

            return;
        }

        $this->startOAuth($paymentMethod, $event);
    }

    private function startOAuth(PaymentMethodInterface $paymentMethod, ResourceControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            // Should never happen
            return;
        }
        try {
            $request->getSession()->set('payplug_sylius_oauth_payment_method_id', $paymentMethod->getId());
            $setupRedirection = $this->router->generate('payplug_sylius_admin_auth_setup_redirection', referenceType: RouterInterface::ABSOLUTE_URL);
            $oauthCallback = $this->router->generate('payplug_sylius_admin_auth_oauth_callback', referenceType: RouterInterface::ABSOLUTE_URL);

            /** @var string $payplugRedirectUrl */
            $payplugRedirectUrl = Authentication::getRegisterUrl($setupRedirection, $oauthCallback);
            $event->setResponse(new RedirectResponse($payplugRedirectUrl));
        } catch (\Throwable $e) {
            $this->logger->critical('Error while starting Payplug OAuth process', ['message' => $e->getMessage(), 'exception' => $e]);
            $request->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.admin.oauth_setup_error');
        }
    }
}
