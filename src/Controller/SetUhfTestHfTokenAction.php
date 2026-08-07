<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

/*
 * ============================================================================
 * TEMPORARY DEV/QA-ONLY SCAFFOLDING - NOT PRODUCTION FUNCTIONALITY
 * ============================================================================
 * This controller (and its route) exist ONLY to unblock manual QA of the
 * Unified Hosted Fields capture flow (PRE-3551) while PRE-3550 (real checkout
 * tokenization) is still in progress.
 *
 * Usage: a developer/tester visits /payplug/uhf/test-token?hfToken=... once
 * per browser session, then goes through checkout choosing the Unified
 * Hosted Fields payment method as usual. HfTokenProvider::getHfToken()
 * (see src/Provider/Payment/HfTokenProvider.php) falls back to the value
 * stored here when Payment::details['hf_token'] is not populated.
 *
 * DELETE THIS WHOLE FILE AND ITS ROUTE once PRE-3550 ships and the real
 * checkout flow always populates Payment::details['hf_token'].
 * ============================================================================
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class SetUhfTestHfTokenAction
{
    /**
     * NOTE: this literal must stay in sync with
     * HfTokenProvider::TEST_SESSION_KEY (private const, so it cannot be
     * imported directly). Update both if this value ever changes.
     */
    private const TEST_SESSION_KEY = 'payplug_uhf_test_hf_token';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    #[Route(path: '/payplug/uhf/test-token', name: 'payplug_sylius_uhf_test_token', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $hfToken = $request->query->getString('hfToken');

        if ('' === $hfToken) {
            throw new BadRequestHttpException('Missing "hfToken" query parameter.');
        }

        $this->requestStack->getSession()->set(self::TEST_SESSION_KEY, $hfToken);

        return new Response('Test hfToken stored for this session. You can now pay with Unified Hosted Fields.');
    }
}
