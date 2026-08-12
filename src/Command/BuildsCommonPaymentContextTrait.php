<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\IntegrationDescriptionProvider;
use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared by CaptureHostedPaymentRequestHandler and CaptureAliasPaymentRequestHandler, the two
 * sibling capture paths that each build a CommonFieldsDto/BrowserDto for the Unified API the same
 * way, differing only in the final HostedFieldDto/PaymentDto they build around it. Requires the
 * host class to have $urlGenerator, $afterPayUrlProvider, $orderAddressDtoFactory and
 * $requestStack properties of the usual types.
 */
trait BuildsCommonPaymentContextTrait
{
    /**
     * @return array{0: string, 1: string} accountId, submerchantExternalId
     */
    private function resolveGatewayCredentials(PaymentMethodInterface $method): array
    {
        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $accountId = $gatewayConfig[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null;
        $submerchantExternalId = $gatewayConfig[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null;
        if (!\is_string($accountId) || '' === $accountId || !\is_string($submerchantExternalId) || '' === $submerchantExternalId) {
            throw new \LogicException('Hosted Fields account id or submerchant id is not configured for this payment method.');
        }

        return [$accountId, $submerchantExternalId];
    }

    private function buildCommonFields(
        string $accountId,
        int $amount,
        string $currencyCode,
        string $orderId,
        string $submerchantExternalId,
        PaymentRequestInterface $paymentRequest,
        ?OrderInterface $order,
    ): CommonFieldsDto {
        $common = new CommonFieldsDto($accountId, $amount, \strtoupper($currencyCode), $orderId, $submerchantExternalId);
        $common->description = IntegrationDescriptionProvider::build();
        $common->notificationUrl = $this->urlGenerator->generate(
            'sylius_payment_request_notify',
            ['hash' => (string) $paymentRequest->getHash()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $successUrl = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $common->successUrl = $successUrl;
        $common->cancelUrl = $successUrl . '?' . http_build_query(['status' => 'canceled']);
        if (null !== $order) {
            $common->billing = $this->orderAddressDtoFactory->createBilling($order);
            $common->shipping = $this->orderAddressDtoFactory->createShipping($order);
        }

        return $common;
    }

    private function buildBrowserDto(): ?BrowserDto
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request
            ? new BrowserDto(
                $request->getClientIp() ?? '',
                $request->headers->get('referer', '') ?? '',
                $request->headers->get('User-Agent', '') ?? '',
            )
            : null;
    }

    private static function idToString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
