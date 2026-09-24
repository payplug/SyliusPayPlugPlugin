<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\Payment\AuthorizationOperationException;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AuthorizedPaymentOperationProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Admin entry points for capturing and cancelling a deferred-capture Hosted Fields payment from
 * the order screen (templates/admin/order/show/authorization.html.twig). Thin on purpose: the
 * rules, the lock and the state transitions all live in AuthorizedPaymentOperationProcessor;
 * this only turns the merchant's form into a call to it, and its outcome into a flash message.
 *
 * POST with the CSRF token in the body — unlike UnifiedLogoutController, these forms are not
 * nested inside another one.
 *
 * Routed from config/routing/admin.yaml rather than with #[Route] attributes: an application using
 * Symfony's `routing.controllers` import (the 7.3+ skeleton default) registers every attribute
 * route of a controller service on its own, without the admin prefix that file applies — which
 * would put these money-moving actions outside the admin firewall. The role check below backs that
 * up should the routes ever end up mounted elsewhere.
 */
#[AsController]
final class AuthorizedPaymentController
{
    /**
     * Per payment, so a token minted for one payment's form cannot be replayed against another.
     * Public because the template that renders the forms mints the token with it.
     */
    public const CSRF_TOKEN_ID_PREFIX = 'payplug_authorization_';

    // The role Sylius's own admin access_control requires.
    private const ADMIN_ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    private const FLASH_PREFIX = 'payplug_sylius_payplug_plugin.admin.authorization.';

    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private AuthorizedPaymentOperationProcessor $processor,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager,
        private LoggerInterface $logger,
    ) {
    }

    public function capture(Request $request, int $orderId, int $paymentId): Response
    {
        return $this->handle($request, $orderId, $paymentId, 'capture', function (PaymentInterface $payment, ?int $amount, ?int $version): void {
            $this->processor->capture($payment, $amount, $version);
        });
    }

    public function cancel(Request $request, int $orderId, int $paymentId): Response
    {
        return $this->handle($request, $orderId, $paymentId, 'cancel', function (PaymentInterface $payment, ?int $amount, ?int $version): void {
            $this->processor->cancel($payment, $amount, $version);
        });
    }

    private function handle(
        Request $request,
        int $orderId,
        int $paymentId,
        string $action,
        \Closure $operation,
    ): Response
    {
        if (!$this->authorizationChecker->isGranted(self::ADMIN_ROLE)) {
            throw new AccessDeniedHttpException('Only an administrator may capture or cancel a payment.');
        }

        $this->denyUnlessCsrfTokenIsValid($request, $paymentId);
        $payment = $this->findOrderPayment($orderId, $paymentId);

        try {
            $operation($payment, self::parseAmount($request->request->getString('amount')), self::parseVersion($request));
            $this->entityManager->flush();
            $this->addFlashMessage($request, 'success', self::FLASH_PREFIX . $action . '_success');
        } catch (AuthorizationOperationException $exception) {
            $this->addFlashMessage($request, 'error', $exception->getTranslationKey(), $exception->getTranslationParameters());
        } catch (\Throwable $exception) {
            // Reached only after the Unified API accepted the operation (e.g. the flush failed):
            // the money moved, but Sylius may not reflect it — worth a human's attention.
            $this->logger->critical('[PayPlug][UPC] Unexpected error during an authorization operation.', [
                'sylius_payment_id' => $paymentId,
                'action' => $action,
                'exception' => $exception,
            ]);
            $this->addFlashMessage($request, 'error', self::FLASH_PREFIX . 'error.api_error');
        }

        return new RedirectResponse($this->router->generate('sylius_admin_order_show', ['id' => $orderId]));
    }

    /**
     * Blank means "the whole remaining amount". Accepts a decimal comma as well as a dot, since
     * that is what a French-speaking merchant types; anything else malformed is sent on as an
     * invalid amount (0) for the processor to refuse with its own explicit message.
     */
    public static function parseAmount(string $raw): ?int
    {
        $raw = \str_replace([' ', "\u{00A0}", ','], ['', '', '.'], \trim($raw));
        if ('' === $raw) {
            return null;
        }

        if (1 !== \preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $raw, $matches)) {
            return 0;
        }

        return (int) $matches[1] * 100 + (int) \str_pad($matches[2] ?? '0', 2, '0');
    }

    private static function parseVersion(Request $request): ?int
    {
        $version = $request->request->get('version');

        return \is_numeric($version) ? (int) $version : null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function addFlashMessage(Request $request, string $type, string $message, array $parameters = []): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            // Sylius's admin flash template translates a ['message', 'parameters'] pair itself.
            $session->getFlashBag()->add($type, [] === $parameters ? $message : ['message' => $message, 'parameters' => $parameters]);
        }
    }

    /**
     * See UnifiedLogoutController::denyUnlessCsrfTokenIsValid() for why a null token manager
     * skips the check rather than failing it.
     */
    private function denyUnlessCsrfTokenIsValid(Request $request, int $paymentId): void
    {
        if (null === $this->csrfTokenManager) {
            return;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID_PREFIX . $paymentId, $request->request->getString('_csrf_token'));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token for the PayPlug authorization request.');
        }
    }

    private function findOrderPayment(int $orderId, int $paymentId): PaymentInterface
    {
        $payment = $this->paymentRepository->find($paymentId);

        if (!$payment instanceof PaymentInterface || $payment->getOrder()?->getId() !== $orderId) {
            throw new NotFoundHttpException(\sprintf('No payment %d found on order %d.', $paymentId, $orderId));
        }

        return $payment;
    }
}
