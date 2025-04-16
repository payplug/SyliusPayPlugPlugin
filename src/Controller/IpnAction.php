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
use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Webmozart\Assert\Assert;

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

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->getContent();

        if (!is_string($input)) {
            throw new LogicException('Input must be of type string.');
        }

        $content = json_decode($input, true);
        $details = ArrayObject::ensureArrayObject($content);

        // if we are too fast canceling a payment before we got an answer from PayPlug gateway
        if (null === $details['payment_id']) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        $payment = $this->paymentRepository->findOneByPayPlugPaymentId($details['payment_id']);
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

        $this->payPlugApiClient = $this->apiClientFactory->create($factoryName, $gatewayConfig['secretKey']);
        $this->payPlugApiClient->initialise($gatewayConfig['secretKey']);

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
