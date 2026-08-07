<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\Payment;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class HfTokenProvider
{
    /**
     * TEMPORARY: only used until PRE-3550 (checkout hosted-fields tokenization) lands. Remove this
     * fallback and the /payplug/uhf/test-token route (Task 3 of this plan) once
     * Payment::details['hf_token'] is always populated by the real checkout flow.
     */
    private const TEST_SESSION_KEY = 'payplug_uhf_test_hf_token';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function getHfToken(PaymentInterface $payment): ?string
    {
        $details = $payment->getDetails();
        $hfToken = $details['hf_token'] ?? null;

        if (\is_string($hfToken) && '' !== $hfToken) {
            return $hfToken;
        }

        $testToken = $this->requestStack->getSession()->get(self::TEST_SESSION_KEY);

        return \is_string($testToken) && '' !== $testToken ? $testToken : null;
    }
}
