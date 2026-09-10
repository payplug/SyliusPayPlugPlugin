<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Payplug\Exception\PayplugException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * @deprecated Legacy Payum-era static webhook receiver for the non-Unified-API (SDK-based)
 *             gateways — Oney, Bancontact, Apple Pay, and the legacy card flow. Superseded by
 *             Sylius's native per-payment-method notify mechanism: NotifyPaymentProvider and
 *             NotifyRefundPaymentProvider (both #[AsNotifyPaymentProvider]) already route these
 *             gateways' real notifications through sylius_payment_method_notify
 *             (/payment-methods/{code}) instead, via the notification_url PayPlugPaymentDataCreator
 *             sends at payment-creation time. Unified API traffic (Hosted Fields and any future
 *             UPC-backed method) never used this branch — see UnifiedApiIpnAction instead, which
 *             needs its own fixed, parameter-less URL since PayPlug's Unified API notifier
 *             Receiver is configured once per merchant in Cockpit and cannot target a
 *             per-payment-method route. Kept, rather than deleted outright, until it's confirmed
 *             no already-onboarded merchant's account still has a webhook pointed at this route
 *             for the legacy flow.
 */
#[AsController]
class IpnAction
{
    private PayPlugApiClientInterface $payPlugApiClient;

    public function __construct(
        private LoggerInterface $logger,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentRepositoryInterface $paymentRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/payplug/ipn', name: 'payplug_sylius_ipn', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->getContent();

        if (!is_string($input)) {
            throw new LogicException('Input must be of type string.');
        }

        $content = json_decode($input, true);
        $details = ArrayObject::ensureArrayObject($content);

        // if we are too fast canceling a payment before we got an answer from PayPlug gateway
        if (null === $details['id']) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        $payment = $this->paymentRepository->findOneByPayPlugPaymentId($details['id']);

        if (null === $payment) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        $paymentMethod = $payment->getMethod();

        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $gateway = $paymentMethod->getGatewayConfig();

        Assert::isInstanceOf($gateway, GatewayConfigInterface::class);
        $gatewayConfig = $gateway->getConfig();

        if (
            !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
            !\in_array($factoryName = $paymentMethod->getGatewayConfig()->getFactoryName(), [
                PayPlugGatewayFactory::FACTORY_NAME,
                OneyGatewayFactory::FACTORY_NAME,
                BancontactGatewayFactory::FACTORY_NAME,
                ApplePayGatewayFactory::FACTORY_NAME,
            ], true)
        ) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        $this->payPlugApiClient = $this->apiClientFactory->create($factoryName);

        try {
            $resource = $this->payPlugApiClient->treat($input);

            $this->paymentNotificationHandler->treat($payment, $resource, $details);
            $this->refundNotificationHandler->treat($payment, $resource, $details);
            $this->entityManager->flush();
        } catch (PayplugException $exception) {
            $details['status'] = PayPlugApiClientInterface::FAILED;
            $this->logger->error('[PayPlug] Notify action', ['error' => $exception->getMessage()]);
        }

        return new JsonResponse();
    }
}
