<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\GatewayConnectionRevoker;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Disconnects one gateway config from its PayPlug account — the counterpart of
 * {@see UnifiedAuthenticationController}, kept apart from it because it shares none of the OAuth
 * dance's session state.
 *
 * Distinct from the `renew_oauth` checkbox, which immediately starts a *new* authorization and
 * ends with credentials again. Logout ends with none, and with the gateway disabled.
 *
 * GET rather than POST: the button lives inside the Sylius payment-method form, where a nested
 * <form> would be invalid HTML. The CSRF token is therefore carried in the query string and
 * checked here — it is the only thing between a crafted link and a merchant losing a connection.
 */
#[Route('/payplug/auth')]
final class UnifiedLogoutController extends AbstractController
{
    /**
     * Per-payment-method so a token minted for one gateway cannot be replayed against another.
     * Public because the template that renders the button mints the token with it.
     */
    public const CSRF_TOKEN_ID_PREFIX = 'payplug_logout_';

    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethod> $paymentMethodRepository
     */
    public function __construct(
        private RouterInterface $router,
        private RepositoryInterface $paymentMethodRepository,
        private GatewayConnectionRevoker $connectionRevoker,
        private ?CsrfTokenManagerInterface $csrfTokenManager,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/logout/{id}', name: 'payplug_sylius_admin_auth_logout', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function logout(Request $request, int $id): Response
    {
        $this->denyUnlessCsrfTokenIsValid($request, $id);
        $paymentMethod = $this->findConnectedPayPlugPaymentMethod($id);

        try {
            $this->connectionRevoker->revoke($paymentMethod);
            $this->addFlashMessage($request, 'success', 'payplug_sylius_payplug_plugin.admin.logout_success');
        } catch (\Throwable $e) {
            $this->logger->critical('Error while logging out the Payplug gateway', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->addFlashMessage($request, 'error', 'payplug_sylius_payplug_plugin.admin.logout_error');
        }

        return new RedirectResponse($this->router->generate('sylius_admin_payment_method_update', ['id' => $id]));
    }

    /**
     * SessionInterface makes no promise about flashes — only FlashBagAwareSessionInterface does —
     * and the parent's addFlash() would reach for the container instead of the request in hand.
     * A non-flash-aware session means a non-web context, where the message has no one to reach
     * anyway, so it is dropped rather than raised.
     */
    private function addFlashMessage(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * A null token manager means the application runs with CSRF protection off — the service only
     * exists while it is on, hence the optional injection in config/services.yaml. The check is
     * then skipped rather than failed, matching Sylius's own admin actions that carry a token in
     * the query string and guard it with sylius_csrf_protection_enabled().
     */
    private function denyUnlessCsrfTokenIsValid(Request $request, int $id): void
    {
        if (null === $this->csrfTokenManager) {
            return;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID_PREFIX . $id, $request->query->getString('_csrf_token'));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token for the PayPlug logout request.');
        }
    }

    /**
     * 404 rather than a flash for both misses: the only legitimate source of this link is the
     * connected-account block on a PayPlug gateway's own update screen, so an id that names
     * nothing — or names another provider's payment method, which logout must never disable —
     * is a tampered or stale URL, not a merchant mistake worth explaining.
     */
    private function findConnectedPayPlugPaymentMethod(int $id): PaymentMethodInterface
    {
        $paymentMethod = $this->paymentMethodRepository->find($id);

        if (
            !$paymentMethod instanceof PaymentMethodInterface ||
            !str_contains((string) $paymentMethod->getGatewayConfig()?->getFactoryName(), 'payplug')
        ) {
            throw new NotFoundHttpException(sprintf('No PayPlug payment method found with id %d.', $id));
        }

        return $paymentMethod;
    }
}
