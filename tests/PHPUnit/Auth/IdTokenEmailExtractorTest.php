<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\IdTokenEmailExtractor;
use PHPUnit\Framework\TestCase;

final class IdTokenEmailExtractorTest extends TestCase
{
    private IdTokenEmailExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new IdTokenEmailExtractor();
    }

    /**
     * Builds a JWT-shaped string whose payload segment is the given claims. The signature is
     * deliberately a fixed placeholder: the extractor never verifies it (see the class docblock),
     * so a real one would prove nothing that this doesn't.
     *
     * @param array<string, mixed> $claims
     */
    private function idTokenWithClaims(array $claims): string
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode((string) json_encode($claims)) . '.c2lnbmF0dXJl';
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testExtract_returnsTheEmailClaim(): void
    {
        $idToken = $this->idTokenWithClaims(['sub' => 'user_1', 'email' => 'merchant@example.com']);

        self::assertSame('merchant@example.com', $this->extractor->extract($idToken));
    }

    /**
     * The payload is base64url-encoded, not plain base64: real PayPlug id tokens routinely contain
     * `-` and `_` where standard base64 would emit `+` and `/`, and are stripped of `=` padding.
     * Decoding one with plain base64_decode() would corrupt the JSON.
     */
    public function testExtract_decodesABase64UrlPayloadContainingUrlUnsafeCharacters(): void
    {
        $claims = ['email' => 'merchant@example.com', 'nonce' => '>>>???~~~'];
        $idToken = $this->idTokenWithClaims($claims);

        self::assertStringNotContainsString('+', explode('.', $idToken)[1]);
        self::assertStringNotContainsString('/', explode('.', $idToken)[1]);
        self::assertSame('merchant@example.com', $this->extractor->extract($idToken));
    }

    // -------------------------------------------------------------------------
    // Absent / malformed input — every branch degrades to null, never throws
    // -------------------------------------------------------------------------

    public function testExtract_returnsNullWhenTheTokenIsNull(): void
    {
        self::assertNull($this->extractor->extract(null));
    }

    public function testExtract_returnsNullWhenTheTokenIsEmpty(): void
    {
        self::assertNull($this->extractor->extract(''));
    }

    public function testExtract_returnsNullWhenTheTokenIsNotThreeSegments(): void
    {
        self::assertNull($this->extractor->extract('header.payload'));
    }

    public function testExtract_returnsNullWhenThePayloadIsNotValidJson(): void
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        self::assertNull($this->extractor->extract($encode('{}') . '.' . $encode('not json') . '.sig'));
    }

    public function testExtract_returnsNullWhenThePayloadIsAJsonScalarRatherThanAnObject(): void
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        self::assertNull($this->extractor->extract($encode('{}') . '.' . $encode('"just-a-string"') . '.sig'));
    }

    public function testExtract_returnsNullWhenTheEmailClaimIsAbsent(): void
    {
        $idToken = $this->idTokenWithClaims(['sub' => 'user_1', 'name' => 'A Merchant']);

        self::assertNull($this->extractor->extract($idToken));
    }

    public function testExtract_returnsNullWhenTheEmailClaimIsNotAString(): void
    {
        $idToken = $this->idTokenWithClaims(['email' => ['merchant@example.com']]);

        self::assertNull($this->extractor->extract($idToken));
    }

    /**
     * The claim is rendered verbatim on an admin screen, so a value that isn't an address is
     * treated as no value at all rather than echoed back.
     */
    public function testExtract_returnsNullWhenTheEmailClaimIsNotAnAddress(): void
    {
        $idToken = $this->idTokenWithClaims(['email' => 'not-an-email']);

        self::assertNull($this->extractor->extract($idToken));
    }

    public function testExtract_returnsNullWhenTheEmailClaimIsEmpty(): void
    {
        $idToken = $this->idTokenWithClaims(['email' => '']);

        self::assertNull($this->extractor->extract($idToken));
    }
}
