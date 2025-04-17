<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\ApiAwareInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class OneClickAction extends AbstractController implements GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private Payum $payum,
        private PayPlugApiClientFactory $payPlugApiClientFactory,
    ) {
    }

    #[Route(path: '/{_locale}/payment/capture/{payum_token}/1-click-checkup', name: 'payplug_sylius_oneclick_verification', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $token = $this->payum->getHttpRequestVerifier()->verify($request);

        /** @var PaymentInterface $payment */
        $payment = $this->paymentRepository->find($token->getDetails()->getId());

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        /** @var GatewayConfigInterface $paymentGateway */
        $paymentGateway = $paymentMethod->getGatewayConfig();

        $captureToken = $this->payum->getTokenFactory()->createCaptureToken(
            $paymentGateway->getGatewayName(),
            $payment,
            'sylius_shop_order_thank_you',
            [],
        );

        $secretKey = $paymentGateway->getConfig()['secretKey'];
        $this->payPlugApiClient = $this->payPlugApiClientFactory->create(PayPlugGatewayFactory::FACTORY_NAME, $secretKey);
        $resource = $this->payPlugApiClient->retrieve((string) $payment->getDetails()['payment_id']);

        //if is_paid is true, you can consider the payment as being fully paid,
        if ($resource->is_paid) {
            return new RedirectResponse($payment->getDetails()['hosted_payment']['return_url']);
        }

        //if both fields authorization and authorized_at are present and filled, the authorization was successful
        if (
            $resource->__isset('authorization') &&
            $resource->__isset('authorized_at') &&
            null !== $resource->__get('authorization') &&
            null !== $resource->__get('authorized_at')
        ) {
            return new RedirectResponse($captureToken->getTargetUrl());
        }

        //if you got a failure, well you got a failed payment
        if ($resource->__isset('failure') && null !== $resource->__get('failure')) {
            return new RedirectResponse($captureToken->getTargetUrl());
        }

        //otherwise you’ll have a hosted_payment.payment_url where the payer has to be redirected to complete the payment.
        return $this->render('@PayPlugSyliusPayPlugPlugin/shop/1click.html.twig', [
            'payment_url' => $payment->getDetails()['hosted_payment']['payment_url'],
        ]);
    }
}
