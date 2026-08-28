<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Stringifies a Sylius resource id (Doctrine ids are typed mixed) for the Unified API contexts
 * that need one as a string — shared by PaymentCaptureContextBuilder and
 * PaymentCaptureOutcomeApplier, kept standalone so neither has to depend on the other for it.
 */
final class ResourceIdentifier
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function toString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
