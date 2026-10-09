<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Exception\Payment;

/**
 * A capture/cancellation of an authorized payment that was refused — locally, or by the Unified
 * API — before anything was recorded or any payment transition applied. Carries a translation
 * key rather than a sentence: the admin shows the merchant an explicit, actionable reason, while
 * the raw upstream message (which may describe infrastructure details) stays in the log.
 */
final class AuthorizationOperationException extends \RuntimeException
{
    /**
     * @param array<string, string> $translationParameters
     */
    public function __construct(
        private string $translationKey,
        private array $translationParameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($translationKey, 0, $previous);
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, string>
     */
    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
