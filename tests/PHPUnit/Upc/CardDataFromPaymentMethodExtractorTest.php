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

    public function testExtract_withStoredId_returnsItAsTheAliasId(): void
    {
        $body = json_encode(['paymentMethod' => ['storedId' => 'card_stored']]);

        self::assertSame(['aliasId' => 'card_stored'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    public function testExtract_withBothStoredIdAndId_prefersStoredId(): void
    {
        $body = json_encode(['paymentMethod' => ['storedId' => 'card_stored', 'id' => 'card_other']]);

        self::assertSame(['aliasId' => 'card_stored'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    /** @dataProvider unusableStoredIds */
    public function testExtract_withAnUnusableStoredId_fallsBackToId(mixed $storedId): void
    {
        $body = json_encode(['paymentMethod' => ['storedId' => $storedId, 'id' => 'card_xxx']]);

        self::assertSame(['aliasId' => 'card_xxx'], CardDataFromPaymentMethodExtractor::extract($body));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function unusableStoredIds(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [123];
        yield 'array' => [['card_stored']];
    }

    public function testExtract_withNeitherStoredIdNorId_returnsNoAliasId(): void
    {
        self::assertSame([], CardDataFromPaymentMethodExtractor::extract(json_encode(['paymentMethod' => ['card' => []]])));
    }

    public function testExtract_withTheRealWebhookShape_extractsTheAliasAndTheCardFields(): void
    {
        $body = json_encode([
            'operationType' => 'PAYMENT',
            'paymentMethod' => [
                'storedId' => 'card_new_1',
                'card' => ['network' => 'VISA', 'type' => 'VISA', 'code6x4' => '446421XXXXXX0000'],
                'details' => ['fullName' => 'John Doe', 'validityDate' => '2030-12', 'selectedBrand' => 'VISA'],
            ],
            'orderId' => '42',
            'id' => '715ac841-1111-4111-8111-111111111111',
        ]);

        self::assertSame([
            'aliasId' => 'card_new_1',
            'brand' => 'VISA',
            'last4' => '0000',
            'expirationYear' => 2030,
            'expirationMonth' => 12,
        ], CardDataFromPaymentMethodExtractor::extract($body));
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
