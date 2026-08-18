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
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
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

/** @deprecated  */
#[AsController]
class IpnAction
{
    private PayPlugApiClientInterface $payPlugApiClient;

    public function __construct(
        private LoggerInterface $logger,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private HostedFieldsWebhookNotificationHandler $hostedFieldsWebhookNotificationHandler,
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

        // Hosted Fields notifications are Unified API webhooks, not legacy SDK ones: a different
        // signature scheme and payload shape that $this->payPlugApiClient->treat() below cannot
        // parse. Branching here — rather than letting it fall through and blow up inside treat()
        // — is what lets this same static, already-deployed IPN URL serve as the account-level
        // webhook receiver for Hosted Fields too, alongside every other gateway's legacy flow.
        if (PayPlugGatewayFactory::isHostedFieldsConfig($gateway)) {
            try {
                $this->hostedFieldsWebhookNotificationHandler->treat($payment, $input, self::flattenHeaders($request->headers->all()));
            } catch (InvalidNotificationException $exception) {
                $this->logger->error('[PayPlug][UPC] Rejected webhook notification.', ['error' => $exception->getMessage()]);
            }

            return new JsonResponse();
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

    /**
     * @param array<string, array<int, string|null>> $rawHeaders
     *
     * @return array<string, string>
     */
    private static function flattenHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        return $headers;
    }
}
