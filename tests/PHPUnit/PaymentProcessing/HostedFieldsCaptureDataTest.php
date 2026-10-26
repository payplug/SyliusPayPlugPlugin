<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\Exception\InvalidHostedFieldsSubmissionException;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsCaptureData;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsCardDetails;
use PHPUnit\Framework\TestCase;

final class HostedFieldsCaptureDataTest extends TestCase
{
    public function testConstruct_withEmptyName_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make('');
    }

    public function testConstruct_withWhitespaceOnlyName_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make('          ');
    }

    public function testConstruct_withNameOf4Chars_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make('abcd');
    }

    public function testConstruct_withPaddedNameOf4RealChars_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make('  abcd  ');
    }

    public function testConstruct_withNameOf46Chars_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make(\str_repeat('a', 46));
    }

    public function testConstruct_withNameOf46MultibyteChars_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make(\str_repeat('é', 46));
    }

    public function testConstruct_withInvalidUtf8Name_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make("\xFF\xFE\xFD\xFC\xFB");
    }

    public function testConstruct_withInvalidUtf8Name_namesTheFieldButNotTheValue(): void
    {
        $name = "John \xFF Doe";

        try {
            $this->make($name);
            self::fail('A cardholder name that is not valid UTF-8 must be rejected.');
        } catch (InvalidHostedFieldsSubmissionException $e) {
            self::assertStringContainsString('cardHolderName', $e->getMessage());
            self::assertStringNotContainsString($name, $e->getMessage());
            self::assertTrue(\mb_check_encoding($e->getMessage(), 'UTF-8'));
        }
    }

    public function testConstruct_withInvalidName_namesTheFieldButNotTheValue(): void
    {
        try {
            $this->make('Jo B');
            self::fail('An invalid cardholder name must be rejected.');
        } catch (InvalidHostedFieldsSubmissionException $e) {
            self::assertStringContainsString('cardHolderName', $e->getMessage());
            self::assertStringNotContainsString('Jo B', $e->getMessage());
        }
    }

    /** @dataProvider provideAcceptedNames */
    public function testConstruct_withNameWithinBounds_isAccepted(string $name): void
    {
        self::assertSame($name, $this->make($name)->cardHolderName);
    }

    public static function provideAcceptedNames(): iterable
    {
        yield '5 chars' => ['abcde'];
        yield '45 chars' => [\str_repeat('a', 45)];
        yield 'accents and umlauts' => ['Zoé Müller'];
        yield '45 multibyte chars (90 bytes)' => [\str_repeat('é', 45)];
    }

    public function testConstruct_trimsTheName(): void
    {
        self::assertSame('John Doe', $this->make('  John Doe ')->cardHolderName);
    }

    /**
     * Same trim as JS String.prototype.trim() in the checkout form, which also strips Unicode
     * spaces such as the no-break space and the BOM.
     *
     * @dataProvider provideUnicodePaddedShortNames
     */
    public function testConstruct_withShortNamePaddedWithUnicodeSpaces_throws(string $name): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make($name);
    }

    public static function provideUnicodePaddedShortNames(): iterable
    {
        yield 'no-break space' => ["\u{00A0}abcd"];
        yield 'narrow no-break space' => ["abcd\u{202F}"];
        yield 'ideographic space' => ["\u{3000}abcd\u{3000}"];
        yield 'byte order mark' => ["\u{FEFF}abcd"];
        yield 'line feed' => ["abcd\n"];
    }

    /**
     * A name typed or pasted in decomposed form ("E" + combining acute accent) is stored and
     * counted composed (NFC), the way the shopper sees it, like the checkout form does.
     */
    public function testConstruct_composesADecomposedName(): void
    {
        self::assertSame("Zo\u{00E9} M\u{00FC}ller", $this->make("Zoe\u{0301} Mu\u{0308}ller")->cardHolderName);
    }

    public function testConstruct_with45DecomposedChars_isAcceptedOnceComposed(): void
    {
        self::assertSame(\str_repeat("\u{00C9}", 45), $this->make(\str_repeat("E\u{0301}", 45))->cardHolderName);
    }

    public function testConstruct_trimsUnicodeSpaces(): void
    {
        self::assertSame('John Doe', $this->make("\u{00A0}John Doe\u{FEFF}\u{3000}")->cardHolderName);
    }

    /** @dataProvider provideNamesWithControlCharacters */
    public function testConstruct_withControlCharacterInTheName_throws(string $name): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make($name);
    }

    public static function provideNamesWithControlCharacters(): iterable
    {
        yield 'line feed' => ["John\nDoe"];
        yield 'null byte' => ["John\0Doe"];
        yield 'tab' => ["John\tDoe"];
        yield 'C1 control' => ["John\u{0085}Doe"];
    }

    public function testConstruct_withControlCharacter_namesTheFieldButNotTheValue(): void
    {
        try {
            $this->make("John\nDoe");
            self::fail('A cardholder name with a control character must be rejected.');
        } catch (InvalidHostedFieldsSubmissionException $e) {
            self::assertStringContainsString('cardHolderName', $e->getMessage());
            self::assertStringNotContainsString('John', $e->getMessage());
        }
    }

    public function testConstruct_keepsTheCardDetails(): void
    {
        $card = new HostedFieldsCardDetails('4242', 12, 2030, 'FR');

        self::assertSame($card, (new HostedFieldsCaptureData('tok', 'VISA', false, $card, 'John Doe'))->card);
    }

    public function testConstruct_withUnknownBrand_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make(brand: 'AMEX');
    }

    public function testConstruct_withEmptyBrand_throws(): void
    {
        $this->expectException(InvalidHostedFieldsSubmissionException::class);

        $this->make(brand: '');
    }

    public function testConstruct_withInvalidBrand_namesTheFieldButNotTheValue(): void
    {
        try {
            $this->make(brand: 'AMEX');
            self::fail('An unknown brand must be rejected.');
        } catch (InvalidHostedFieldsSubmissionException $e) {
            self::assertStringContainsString('selectedBrand', $e->getMessage());
            self::assertStringNotContainsString('AMEX', $e->getMessage());
        }
    }

    public function testConstruct_normalisesBrandCase(): void
    {
        self::assertSame('VISA', $this->make(brand: ' visa ')->selectedBrand);
    }

    /** @dataProvider provideAllowedBrands */
    public function testConstruct_withAllowedBrand_isAccepted(string $brand): void
    {
        self::assertSame($brand, $this->make(brand: $brand)->selectedBrand);
    }

    public static function provideAllowedBrands(): iterable
    {
        yield 'CB' => ['CB'];
        yield 'VISA' => ['VISA'];
        yield 'MASTERCARD' => ['MASTERCARD'];
    }

    private function make(string $name = 'John Doe', string $brand = 'VISA'): HostedFieldsCaptureData
    {
        return new HostedFieldsCaptureData('tok', $brand, false, new HostedFieldsCardDetails('4242', 12, 2030, 'FR'), $name);
    }
}
