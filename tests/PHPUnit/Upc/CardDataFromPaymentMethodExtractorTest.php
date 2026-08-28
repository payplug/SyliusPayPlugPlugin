<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\CardDataFromPaymentMethodExtractor;
use PHPUnit\Framework\TestCase;

final class CardDataFromPaymentMethodExtractorTest extends TestCase
{
    public function testExtract_withAFullRealisticResponse_extractsEveryField(): void
    {
        $body = json_encode([
            'paymentMethod' => [
                'id' => 'card_xxx',
                'card' => [
                    'network' => 'VISA',
                    'code6x4' => '424242XXXXXX4242',
                ],
                'details' => [
                    'selectedBrand' => 'VISA',
                    'validityDate' => '2027-12',
                ],
            ],
        ]);

        self::assertSame([
            'aliasId' => 'card_xxx',
            'brand' => 'VISA',
            'last4' => '4242',
            'expirationYear' => 2027,
            'expirationMonth' => 12,
        ], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_whenCardNetworkAndDetailsSelectedBrandDisagree_cardNetworkWins(): void
    {
        $body = json_encode([
            'paymentMethod' => [
                'card' => ['network' => 'VISA'],
                'details' => ['selectedBrand' => 'MASTERCARD'],
            ],
        ]);

        self::assertSame(['brand' => 'VISA'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withNoCardNetwork_fallsBackToDetailsSelectedBrand(): void
    {
        $body = json_encode([
            'paymentMethod' => [
                'card' => ['code6x4' => '424242XXXXXX4242'],
                'details' => ['selectedBrand' => 'MASTERCARD'],
            ],
        ]);

        $result = CardDataFromPaymentMethodExtractor::extract($body);

        self::assertSame('MASTERCARD', $result['brand']);
    }

    public function testExtract_withNonArrayBody_returnsEmptyArray(): void
    {
        self::assertSame([], CardDataFromPaymentMethodExtractor::extract('"just a string"'));
    }

    public function testExtract_withPaymentMethodKeyMissing_returnsEmptyArray(): void
    {
        self::assertSame([], CardDataFromPaymentMethodExtractor::extract(json_encode(['id' => 'op_1'])));
    }

    public function testExtract_withCardKeyMissing_returnsOnlyAliasId(): void
    {
        $body = json_encode(['paymentMethod' => ['id' => 'card_xxx', 'details' => ['selectedBrand' => 'VISA']]]);

        self::assertSame(['aliasId' => 'card_xxx', 'brand' => 'VISA'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withDetailsKeyMissing_returnsOnlyCardFields(): void
    {
        $body = json_encode(['paymentMethod' => ['card' => ['network' => 'VISA']]]);

        self::assertSame(['brand' => 'VISA'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withEmptyAliasId_omitsAliasId(): void
    {
        $body = json_encode(['paymentMethod' => ['id' => '']]);

        self::assertSame([], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withCode6x4ShorterThanFourCharacters_omitsLast4(): void
    {
        $body = json_encode(['paymentMethod' => ['card' => ['code6x4' => '42']]]);

        self::assertSame([], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withValidityDateNotMatchingTheExpectedFormat_omitsExpiration(): void
    {
        $body = json_encode(['paymentMethod' => ['details' => ['validityDate' => '1225']]]);

        self::assertSame([], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withValidityDateOutOfRangeMonth_omitsExpiration(): void
    {
        $body = json_encode(['paymentMethod' => ['details' => ['validityDate' => '2027-13']]]);

        self::assertSame([], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtractFromDecoded_withAnAlreadyDecodedBody_behavesLikeExtract(): void
    {
        $decoded = ['paymentMethod' => ['id' => 'card_xxx', 'card' => ['network' => 'VISA']]];

        self::assertSame(
            ['aliasId' => 'card_xxx', 'brand' => 'VISA'],
            CardDataFromPaymentMethodExtractor::extractFromDecoded($decoded),
        );
    }
}
