<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\TestCase;

final class AuthorizationDetailsTest extends TestCase
{
    public function testOpen_recordsTheAuthorizedAmountAndDeadlineFromTheCreationResponse(): void
    {
        $output = new PaymentOutput(201, '{}', null, null, null, '2026-10-01T12:00:00+00:00', 900);

        $details = AuthorizationDetails::open(['hosted_fields_payment_id' => 'pay_1'], $output, 1000);
        $authorization = AuthorizationDetails::fromDetails($details);

        self::assertSame('pay_1', $details['hosted_fields_payment_id']);
        self::assertTrue($authorization->isDeferred());
        // The response's amount wins over the Sylius one: the issuer may authorize less.
        self::assertSame(900, $authorization->authorizedAmount());
        self::assertSame(900, $authorization->remainingAmount());
        self::assertEquals(new \DateTimeImmutable('2026-10-01T12:00:00+00:00'), $authorization->maxCaptureDate());
        self::assertSame(0, $authorization->version());
    }

    public function testOpen_fallsBackToThePaymentAmountWhenTheResponseCarriesNone(): void
    {
        // A 3DS-pending response: the authorization isn't granted yet, so it carries no amount.
        $output = new PaymentOutput(201, '{}', null, '<form></form>', null);

        $authorization = AuthorizationDetails::fromDetails(AuthorizationDetails::open([], $output, 1000));

        self::assertSame(1000, $authorization->authorizedAmount());
        self::assertNull($authorization->maxCaptureDate());
    }

    public function testRemainingAmount_subtractsCapturesAndCancellationsButNotFailedOnes(): void
    {
        $details = $this->openedDetails(1000);
        $details = AuthorizationDetails::withOperation($details, AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 300);
        $details = AuthorizationDetails::withOperation($details, AuthorizationDetails::OPERATION_CAPTURE, 'op_c2', 200);
        $details = AuthorizationDetails::withOperation($details, AuthorizationDetails::OPERATION_CANCELLATION, 'op_v1', 100);
        $details = AuthorizationDetails::withOperationFailed($details, AuthorizationDetails::OPERATION_CAPTURE, 'op_c2');

        $authorization = AuthorizationDetails::fromDetails($details);

        self::assertSame(300, $authorization->capturedAmount());
        self::assertSame(100, $authorization->cancelledAmount());
        self::assertSame(600, $authorization->remainingAmount());
        self::assertTrue($authorization->hasCaptures());
        // A failed operation still counts toward the version: it was attempted, and a form built
        // before it is stale either way.
        self::assertSame(3, $authorization->version());
    }

    public function testFindOperation_tellsCapturesAndCancellationsApart(): void
    {
        $details = $this->openedDetails(1000);
        $details = AuthorizationDetails::withOperation($details, AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 300);
        $details = AuthorizationDetails::withOperation($details, AuthorizationDetails::OPERATION_CANCELLATION, 'op_v1', 100);

        $authorization = AuthorizationDetails::fromDetails($details);

        self::assertSame(['operation' => 'capture', 'amount' => 300], $authorization->findOperation('op_c1'));
        self::assertSame(['operation' => 'cancellation', 'amount' => 100], $authorization->findOperation('op_v1'));
        self::assertNull($authorization->findOperation('op_unknown'));
    }

    public function testResolveCreationOutcome_readsSuccessAsAuthorizedOnlyForADeferredPayment(): void
    {
        $deferred = AuthorizationDetails::fromDetails($this->openedDetails(1000));
        $immediate = AuthorizationDetails::fromDetails([]);

        self::assertSame(PaymentOutcome::AUTHORIZED, $deferred->resolveCreationOutcome(PaymentOutcome::PAID));
        self::assertSame(PaymentOutcome::FAILED, $deferred->resolveCreationOutcome(PaymentOutcome::FAILED));
        self::assertSame(PaymentOutcome::PAID, $immediate->resolveCreationOutcome(PaymentOutcome::PAID));
    }

    public function testExpiry_isComputedAgainstTheDeadline(): void
    {
        $details = [...$this->openedDetails(1000), AuthorizationDetails::MAX_CAPTURE_DATE => '2026-10-01T12:00:00+00:00'];
        $authorization = AuthorizationDetails::fromDetails($details);

        self::assertFalse($authorization->isExpired(new \DateTimeImmutable('2026-09-25T12:00:00+00:00')));
        self::assertFalse($authorization->isExpiringSoon(new \DateTimeImmutable('2026-09-25T12:00:00+00:00')));
        self::assertTrue($authorization->isExpiringSoon(new \DateTimeImmutable('2026-09-30T13:00:00+00:00')));
        self::assertTrue($authorization->isExpired(new \DateTimeImmutable('2026-10-01T12:00:00+00:00')));
        self::assertFalse($authorization->isExpiringSoon(new \DateTimeImmutable('2026-10-02T00:00:00+00:00')));
    }

    public function testExpiry_isNeverAssumedWithoutAKnownDeadline(): void
    {
        $authorization = AuthorizationDetails::fromDetails($this->openedDetails(1000));

        self::assertFalse($authorization->isExpired(new \DateTimeImmutable('2100-01-01')));
        self::assertFalse($authorization->isExpiringSoon(new \DateTimeImmutable('2100-01-01')));
    }

    public function testWithMaxCaptureDateFromBody_onlyOverwritesWithAPresentValue(): void
    {
        $details = $this->openedDetails(1000);

        $updated = AuthorizationDetails::withMaxCaptureDateFromBody($details, '{"maxCaptureDate":"2026-10-01T12:00:00+00:00"}');
        $untouched = AuthorizationDetails::withMaxCaptureDateFromBody($updated, '{"execCode":"0000"}');

        self::assertSame('2026-10-01T12:00:00+00:00', $untouched[AuthorizationDetails::MAX_CAPTURE_DATE]);
    }

    /**
     * @return mixed[]
     */
    private function openedDetails(int $amount): array
    {
        return AuthorizationDetails::open([], new PaymentOutput(201, '{}', null, null, null, null, $amount), $amount);
    }
}
