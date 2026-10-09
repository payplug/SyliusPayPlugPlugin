<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationWebhookBody;
use PHPUnit\Framework\TestCase;

final class AuthorizationWebhookBodyTest extends TestCase
{
    public function testWithMaxCaptureDate_onlyOverwritesWithAPresentValue(): void
    {
        $updated = AuthorizationWebhookBody::withMaxCaptureDate([], '{"maxCaptureDate":"2026-10-01T12:00:00+00:00"}');
        $untouched = AuthorizationWebhookBody::withMaxCaptureDate($updated, '{"execCode":"0000"}');

        self::assertSame('2026-10-01T12:00:00+00:00', $untouched[AuthorizationDetails::MAX_CAPTURE_DATE]);
    }

    public function testWithMaxCaptureDate_ignoresAMalformedBody(): void
    {
        self::assertSame(['a' => 1], AuthorizationWebhookBody::withMaxCaptureDate(['a' => 1], 'not json'));
    }
}
