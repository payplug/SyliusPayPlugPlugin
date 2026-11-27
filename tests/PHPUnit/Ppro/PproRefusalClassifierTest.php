<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefusalClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The bodies below follow the error shape PayPlug uses for a refused parameter, a `details` tree
 * keyed by the request field path (the same shape the legacy capture reads for
 * `details.billing.postcode`). The exact bodies of a country or amount refusal on a PPRO method
 * have not been captured on a sandbox yet.
 */
final class PproRefusalClassifierTest extends TestCase
{
    private PproRefusalClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PproRefusalClassifier();
    }

    public function testCountryRefusalIsClassified(): void
    {
        $body = '{"object":"error","message":"The parameters you sent are invalid.","details":{"billing":{"country":"This payment method is not available for this country."}}}';

        self::assertSame(PproRefusalClassifier::COUNTRY, $this->classifier->classify($body));
    }

    public function testAmountRefusalIsClassified(): void
    {
        $body = '{"object":"error","message":"The parameters you sent are invalid.","details":{"amount":"The amount must be between 1.00 EUR and 2000.00 EUR."}}';

        self::assertSame(PproRefusalClassifier::AMOUNT, $this->classifier->classify($body));
    }

    public function testPostcodeRefusalIsNotClassified(): void
    {
        $body = '{"object":"error","message":"The parameters you sent are invalid.","details":{"billing":{"postcode":"Invalid postcode."}}}';

        self::assertNull($this->classifier->classify($body));
    }

    public function testShippingCountryRefusalIsNotClassified(): void
    {
        self::assertNull($this->classifier->classify('{"details":{"shipping":{"country":"Invalid country."}}}'));
    }

    /**
     * @dataProvider unclassifiableBodies
     */
    public function testEmptyOrNonJsonBodyIsNotClassified(?string $body): void
    {
        self::assertNull($this->classifier->classify($body));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function unclassifiableBodies(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'html' => ['<html>502 Bad Gateway</html>'];
        yield 'truncated json' => ['{"details":{"amount"'];
        yield 'json scalar' => ['"amount"'];
        yield 'details is a string' => ['{"details":"amount"}'];
        yield 'billing is a string' => ['{"details":{"billing":"country"}}'];
        yield 'message only' => ['{"message":"The amount is too low."}'];
    }
}
