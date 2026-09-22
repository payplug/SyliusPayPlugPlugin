<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Extracts the alias id and card metadata from a decoded Unified API response body's
 * `paymentMethod` object — shared by CaptureHostedPaymentRequestHandler (parsing the operation
 * resource fetched via OperationStatusFetcherInterface) and HostedFieldsWebhookNotificationHandler
 * (parsing the webhook's own raw body directly — confirmed the same shape as the operation
 * resource, so no separate API call is needed there). Confirmed against a real staging response:
 * paymentMethod.id ("card_xxx", the alias), paymentMethod.card.{network, code6x4} ("VISA", a
 * masked PAN "424242XXXXXX4242") and paymentMethod.details.{selectedBrand, validityDate} ("VISA",
 * "2027-12" — YYYY-MM). last4 isn't its own field — it's derived from code6x4's last 4 characters.
 */
final class CardDataFromPaymentMethodExtractor
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /** @return array{aliasId?: string, brand?: string, last4?: string, expirationMonth?: int, expirationYear?: int} */
    public static function extract(string $body): array
    {
        return self::extractFromDecoded(\json_decode($body, true));
    }

    /**
     * Same as extract(), but for a caller that already decoded the response body itself (e.g. to
     * also read other top-level fields from it) and would otherwise decode the identical string a
     * second time.
     *
     * @return array{aliasId?: string, brand?: string, last4?: string, expirationMonth?: int, expirationYear?: int}
     */
    public static function extractFromDecoded(mixed $decoded): array
    {
        $paymentMethod = \is_array($decoded) ? ($decoded['paymentMethod'] ?? null) : null;
        if (!\is_array($paymentMethod)) {
            return [];
        }

        $result = self::extractAliasId($paymentMethod);
        $result = [...$result, ...self::extractFromCard($paymentMethod['card'] ?? null)];

        return [...$result, ...self::extractFromDetails($paymentMethod['details'] ?? null, isset($result['brand']))];
    }

    /**
     * @param mixed[] $paymentMethod
     *
     * @return array{aliasId?: string}
     */
    private static function extractAliasId(array $paymentMethod): array
    {
        $aliasId = $paymentMethod['id'] ?? null;

        return \is_string($aliasId) && '' !== $aliasId ? ['aliasId' => $aliasId] : [];
    }

    /** @return array{brand?: string, last4?: string} */
    private static function extractFromCard(mixed $card): array
    {
        if (!\is_array($card)) {
            return [];
        }

        $result = [];

        $network = $card['network'] ?? null;
        if (\is_string($network) && '' !== $network) {
            $result['brand'] = $network;
        }

        $code6x4 = $card['code6x4'] ?? null;
        if (\is_string($code6x4) && \strlen($code6x4) >= 4) {
            $result['last4'] = \substr($code6x4, -4);
        }

        return $result;
    }

    /**
     * @param bool $hasBrand true when a brand was already resolved from the card object, taking
     *                       precedence over the details' own selectedBrand
     *
     * @return array{brand?: string, expirationMonth?: int, expirationYear?: int}
     */
    private static function extractFromDetails(mixed $cardDetails, bool $hasBrand): array
    {
        if (!\is_array($cardDetails)) {
            return [];
        }

        $result = [];

        $selectedBrand = $cardDetails['selectedBrand'] ?? null;
        if (!$hasBrand && \is_string($selectedBrand) && '' !== $selectedBrand) {
            $result['brand'] = $selectedBrand;
        }

        $validityDate = $cardDetails['validityDate'] ?? null;
        if (\is_string($validityDate) && 1 === \preg_match('/^(\d{4})-(\d{2})$/', $validityDate, $matches)) {
            $month = (int) $matches[2];
            if ($month >= 1 && $month <= 12) {
                $result['expirationYear'] = (int) $matches[1];
                $result['expirationMonth'] = $month;
            }
        }

        return $result;
    }
}
