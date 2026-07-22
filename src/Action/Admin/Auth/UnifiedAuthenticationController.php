<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Authentication;
use Payplug\Payplug;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use Psr\Log\LoggerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * This controller is used to authenticate the user with PayPlug
 *
 * The OAuth process start when creating a new payment method or updated it.
 *
 * @see PayPlug\SyliusPayPlugPlugin\EventListener\PostSavePaymentMethodEventListener
 */
#[Route('/payplug/auth')]
final class UnifiedAuthenticationController extends AbstractController
{
    // Matches the scope legacy Authentication::initiateOAuth() has always requested.
    private const PKCE_SCOPE = 'openid offline profile email';

    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethod> $paymentMethodRepository
     */
    public function __construct(
        private RouterInterface $router,
        private RepositoryInterface $paymentMethodRepository,
        private EntityManagerInterface $entityManager,
        private PaymentMethodValidator $paymentMethodValidator,
        private LoggerInterface $logger,
        private IOAuthHttpClient $oauthHttpClient,
        private string $payplugOauthBaseUrl,
        private string $payplugOauthAudience,
    ) {
    }

    private function buildOAuth2Client(string $redirectUri): OAuth2Client
    {
        return new OAuth2Client($this->oauthHttpClient, $this->payplugOauthBaseUrl, $redirectUri, self::PKCE_SCOPE, $this->payplugOauthAudience);
    }

    #[Route('/setup-redirection', name: 'payplug_sylius_admin_auth_setup_redirection')]
    public function setupRedirection(Request $request): Response
    {
        try {
            $clientId = $request->query->getString('client_id');
            $companyId = $request->query->getString('company_id');

            $request->getSession()->set('payplug_client_id', $clientId);
            $request->getSession()->set('payplug_company_id', $companyId);

            $callBackUrl = $this->router->generate('payplug_sylius_admin_auth_oauth_callback', [], RouterInterface::ABSOLUTE_URL);
            $authorizationRequest = $this->buildOAuth2Client($callBackUrl)->buildAuthorizationUrl($clientId);
            $request->getSession()->set('payplug_oauth_state', $authorizationRequest->state);
            $request->getSession()->set('payplug_oauth_code_verifier', $authorizationRequest->codeVerifier);

            return new RedirectResponse($authorizationRequest->url);
        } catch (\Throwable $e) {
            $this->logger->critical('Error while perform Payplug OAuth Setup redirection', ['message' => $e->getMessage(), 'exception' => $e]);

            return $this->handleOAuthError($request);
        }
    }

    #[Route('/oauth-callback', name: 'payplug_sylius_admin_auth_oauth_callback')]
    public function oauthCallback(Request $request): Response
    {
        try {
            $code = $request->query->getString('code');
            $state = $request->query->getString('state');
            /** @var string $clientId */
            $clientId = $request->getSession()->get('payplug_client_id');
            /** @var string $expectedState */
            $expectedState = $request->getSession()->get('payplug_oauth_state');
            $codeVerifier = $request->getSession()->get('payplug_oauth_code_verifier');

            if ('' === $state || $state !== $expectedState) {
                throw new BadRequestHttpException('OAuth state mismatch');
            }

            if (!\is_string($codeVerifier) || '' === $codeVerifier) {
                throw new BadRequestHttpException('OAuth code verifier missing from session');
            }

            $callback = $this->generateUrl('payplug_sylius_admin_auth_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $token = $this->buildOAuth2Client($callback)->exchangeAuthorizationCode($clientId, $code, $codeVerifier);

            $paymentMethodId = $request->getSession()->get('payplug_sylius_oauth_payment_method_id');
            if (null === $paymentMethodId) {
                throw new BadRequestHttpException('No payment method id found in session');
            }
            $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
            if (null === $paymentMethod) {
                throw new \LogicException('No payment method found');
            }
            $gatewayConfig = $paymentMethod->getGatewayConfig();
            if (null === $gatewayConfig) {
                throw new \LogicException('No gateway config found');
            }

            $companyId = $request->getSession()->get('payplug_company_id');
            Payplug::init(['secretKey' => $token->accessToken]);
            $clientName = 'Sylius - ' . $paymentMethod->getName();
            $testClientDataResult = Authentication::createClientIdAndSecret($companyId, $clientName, 'test');
            $liveClientDataResult = Authentication::createClientIdAndSecret($companyId, $clientName, 'live');

            $config = $gatewayConfig->getConfig();
            $config['live_client'] = $liveClientDataResult['httpResponse'] ?? null;
            $config['test_client'] = $testClientDataResult['httpResponse'] ?? null;
            $gatewayConfig->setConfig($config);

            $this->entityManager->flush();
            $this->cleanSession($request);

            $request->getSession()->getFlashBag()->add('success', 'payplug_sylius_payplug_plugin.admin.oauth_callback_success');
            // Ensure that the payment method is well configured
            $this->paymentMethodValidator->process($paymentMethod);

            return new RedirectResponse($this->router->generate('sylius_admin_payment_method_update', ['id' => $paymentMethod->getId()]));
        } catch (\Throwable $e) {
            $this->logger->critical('Error while perform Payplug OAuth callback', ['message' => $e->getMessage(), 'exception' => $e]);

            return $this->handleOAuthError($request);
        }
    }

    private function handleOAuthError(Request $request): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.admin.oauth_setup_error');
        $paymentMethodId = $request->getSession()->get('payplug_sylius_oauth_payment_method_id');
        if (null === $paymentMethodId) {
            return new RedirectResponse($this->router->generate('sylius_admin_payment_method_index'));
        }

        return new RedirectResponse($this->router->generate('sylius_admin_payment_method_update', ['id' => $paymentMethodId]));
    }

    private function cleanSession(Request $request): void
    {
        $session = $request->getSession();
        $session->remove('payplug_client_id');
        $session->remove('payplug_company_id');
        $session->remove('payplug_oauth_state');
        $session->remove('payplug_oauth_code_verifier');
        $session->remove('payplug_sylius_oauth_payment_method_id');
    }
}
