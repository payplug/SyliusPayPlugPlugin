<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * Static, account-level webhook receiver for Unified API (UPC) payment methods — currently only
 * Hosted Fields, but deliberately named and scoped around "Unified API" rather than "Hosted
 * Fields", since more UPC-backed payment methods are expected to land on this same route over
 * time.
 *
 * A dedicated route rather than a branch on IpnAction (which handles every other, legacy-SDK
 * gateway): the Unified API's real notification delivery mechanism is PayPlug's account/realm-
 * scoped "Receiver", configured once in Cockpit with a fixed URL — no path parameters allowed.
 * That rules out Sylius's own native per-payment-method notify route
 * (sylius_payment_method_notify, /payment-methods/{code}) the legacy SDK gateways already use via
 * PayPlugPaymentDataCreator, since {code} still varies per installation. IpnAction itself is
 * intentionally left untouched, back to legacy-SDK-only (its Hosted Fields branch, added in
 * PRE-3614, was extracted here).
 */
#[AsController]
class UnifiedApiIpnAction
{
    public function __construct(
        private LoggerInterface $logger,
        private HostedFieldsWebhookNotificationHandler $hostedFieldsWebhookNotificationHandler,
        private PaymentRepositoryInterface $paymentRepository,
    ) {
    }

    #[Route(path: '/payplug/v2/ipn', name: 'payplug_sylius_unified_api_ipn', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->getContent();
        $payment = $this->resolveHostedFieldsPayment($input);

        if (null === $payment) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->hostedFieldsWebhookNotificationHandler->treat($payment, $input, self::flattenHeaders($request->headers->all()));
        } catch (InvalidNotificationException $exception) {
            $this->logger->error('[PayPlug][UPC] Rejected webhook notification.', ['error' => $exception->getMessage()]);
        }

        return new JsonResponse();
    }

    // Split out of __invoke() to keep its own return count within SonarCloud's limit (php:S1142)
    // — collapses the three "reject this notification" conditions (missing id, unknown payment,
    // wrong gateway) into a single null-or-not result.
    private function resolveHostedFieldsPayment(string $input): ?PaymentInterface
    {
        $content = json_decode($input, true);
        $id = \is_array($content) ? ($content['id'] ?? null) : null;

        // if we are too fast canceling a payment before we got an answer from PayPlug gateway
        $payment = \is_string($id) && '' !== $id ? $this->paymentRepository->findOneByPayPlugPaymentId($id) : null;
        if (null === $payment) {
            return null;
        }

        $paymentMethod = $payment->getMethod();
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $gateway = $paymentMethod->getGatewayConfig();
        Assert::isInstanceOf($gateway, GatewayConfigInterface::class);

        // Defensive: this route is dedicated to Unified API traffic, so a resolved payment that
        // isn't actually on a Unified API-backed config (currently, only Hosted Fields) is
        // rejected rather than guessed at. Update this check if/when a second Unified API-backed
        // payment method is added.
        return PayPlugGatewayFactory::isHostedFieldsConfig($gateway) ? $payment : null;
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
