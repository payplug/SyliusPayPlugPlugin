<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Authentication;
use Payplug\Payplug;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[Route('/payplug/auth')]
final class UnifiedAuthenticationController extends AbstractController
{
    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethod> $paymentMethodRepository
     */
    public function __construct(
        private RouterInterface $router,
        private RepositoryInterface $paymentMethodRepository,
        private EntityManagerInterface $entityManager,
        private PaymentMethodValidator $paymentMethodValidator,
    ) {
    }

    #[Route('/setup-redirection', name: 'payplug_sylius_admin_auth_setup_redirection')]
    public function setupRedirection(Request $request): void
    {
        $clientId = $request->query->get('client_id');
        $companyId = $request->query->get('company_id');

        $request->getSession()->set('payplug_client_id', $clientId);
        $request->getSession()->set('payplug_company_id', $companyId);

        $challenge = bin2hex(openssl_random_pseudo_bytes(50));
        $request->getSession()->set('payplug_oauth_challenge', $challenge);

        $callBackUrl = $this->router->generate('payplug_sylius_admin_auth_oauth_callback', [], RouterInterface::ABSOLUTE_URL);

        // This method will redirect the user to PayPlug's oauth page via header('Location')'
        Authentication::initiateOAuth($clientId, $callBackUrl, $challenge);
        exit;
    }

    #[Route('/oauth-callback', name: 'payplug_sylius_admin_auth_oauth_callback')]
    public function oauthCallback(Request $request): Response
    {
        $code = $request->query->get('code');
        $clientId = $request->getSession()->get('payplug_client_id');
        $challenge = $request->getSession()->get('payplug_oauth_challenge');
        $callback = $this->generateUrl('payplug_sylius_admin_auth_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $jwt = Authentication::generateJWTOneShot($code, $callback, $clientId, $challenge);
        if ([] === $jwt || $jwt['httpStatus'] !== 200) {
            throw new BadRequestHttpException('Error while generating JWT');
        }
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
        Payplug::init(['secretKey' => $jwt['httpResponse']['access_token']]);
        $clientName = 'Sylius - ' . $paymentMethod->getName();
        $testClientDataResult = Authentication::createClientIdAndSecret($companyId, $clientName, 'test');
        $liveClientDataResult = Authentication::createClientIdAndSecret($companyId, $clientName, 'live');

        $config = $gatewayConfig->getConfig();
        $config['live_client'] = $liveClientDataResult['httpResponse'];
        $config['test_client'] = $testClientDataResult['httpResponse'];
        $gatewayConfig->setConfig($config);

        $this->entityManager->flush();
        $this->cleanSession($request);

        $request->getSession()->getFlashBag()->add('success', 'payplug_sylius_payplug_plugin.admin.oauth_callback_success');

        // Ensure that the payment method is well configured
        $this->paymentMethodValidator->process($paymentMethod);

        return new RedirectResponse($this->router->generate('sylius_admin_payment_method_update', ['id' => $paymentMethod->getId()]));
    }

    private function cleanSession(Request $request): void
    {
        $session = $request->getSession();
        $session->remove('payplug_client_id');
        $session->remove('payplug_company_id');
        $session->remove('payplug_oauth_challenge');
        $session->remove('payplug_sylius_oauth_payment_method_id');
    }
}
