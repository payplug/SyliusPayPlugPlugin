# PRE-3469 rework — real UPC contract wiring

## Context

[PRE-3469](https://payplug-prod.atlassian.net/browse/PRE-3469) originally asked for isolated proof-of-concept skeletons (under `src/Spike/`) validating that 4 Unified Plugin Core (UPC) contracts — `IOrderStateMutator`, `ITokenCache`, `IConfigurationRepository`, `IPaymentRepository` — are satisfiable against real Sylius APIs, before freezing the interfaces. That work landed in commit `690d9f5` and is fully written up in `src/Spike/VALIDATION.md`.

The ticket's "Résultats attendus" were updated twice on 2026-07-20. The current version requires:

- Real, production-wired implementations (not isolated `src/Spike/` skeletons) for `PayplugOrderStateMutator`, `PayplugTokenCache`, `PayplugConfigurationRepository`.
- `IPaymentRepository` explicitly **out of scope** (tied to a Value Object too coupled to the not-yet-built Unified API) — the existing `SyliusPaymentRepository` sketch is sufficient as-is.
- No regression on existing plugin functionality (PHPUnit + Behat stay green).
- Any blocking friction documented, or the interfaces adjusted.

This spec covers reworking the branch to meet the updated scope.

## Existing production code map

Research (see conversation) found the production analogs each contract needs to be validated against:

| Contract | Production analog | Notes |
|---|---|---|
| `IOrderStateMutator` | `PaymentTransitionApplier` (called from `NotifyPaymentRequestHandler`/`StatusPaymentRequestHandler`, the webhook/status-poll path) and `PaymentStateResolver` (called from the CLI reconciliation command `payplug:update-payment-state`) | Two separate, non-unified implementations of the same guarded `can()`/`apply()` idiom already exist in production; this ticket does not unify them. |
| `ITokenCache` | `PayPlugApiClientFactory::getTokenForGatewayConfig()`'s inline `Symfony\Contracts\Cache\CacheInterface` usage, caching the OAuth JWT access token per gateway factory/live-test scope | Confirmed by the ticket's second update: targets the OAuth JWT cache, not card/token storage (saved cards are a permanent Doctrine entity, `Card.php`, uninvolved with any cache). |
| `IConfigurationRepository` | Read side: `PayPlugApiClientFactory::getTokenForGatewayConfig()` (`client_id`/`client_secret` scoped by `config['live']`). Write side: `UnifiedAuthenticationController::oauthCallback()` (persists `live_client`/`test_client`, busts the token cache). | No production equivalent exists for `getPublicKeyId()`/`getPublicKeyValue()` — Hosted Fields public-key material isn't implemented anywhere in the plugin today. |
| `IPaymentRepository` | None — out of scope | `SyliusPaymentRepository` + `PayplugOperation` entity + `Version20260720100000` migration stay as an unwired sketch. |

## Design

### 1. `PayplugOrderStateMutator` — real production call site

Keep the existing mapping logic from `src/Spike/SyliusOrderStateMutator.php`: `PaymentOutcome::PAID/AUTHORIZED/CAPTURE_REQUIRED/REFUNDED/FAILED` → `PaymentTransitions::TRANSITION_COMPLETE/AUTHORIZE/PROCESS/REFUND/FAIL`, `THREE_DS_PENDING` as a confirmed no-op, `StateMachineInterface::can()` guard before `apply()`, explicit `EntityManagerInterface::flush()` after.

Wire it as an **additive** call inside `NotifyPaymentRequestHandler::__invoke()`, immediately after the existing `PaymentTransitionApplier::apply($payment)` call. The handler already has everything needed to derive a `PaymentOutcome` from the same status signal `PaymentTransitionApplier` consumes (`$payment->getDetails()['status']`, populated upstream by `PaymentNotificationHandler::treat()`); translate that into a `PaymentOutcome` value and call `$orderStateMutator->apply($order->getId(), $outcome)`.

Because `PaymentTransitionApplier::apply()` already applied the transition earlier in the same handler invocation, `PayplugOrderStateMutator`'s own `can()` guard will find the transition no longer applicable and no-op. This proves the mutator works against a real, live webhook event without replacing or risking the existing transition-application path. No change to `PaymentStateResolver` or the CLI reconciliation command.

### 2. `PayplugTokenCache` — real integration test, no production call site

No change to `PayPlugApiClientFactory::getTokenForGatewayConfig()`. Promote the spike's existing unit-mocked test (`tests/PHPUnit/Spike/SyliusTokenCacheTest.php`) with a new real-infrastructure test that exercises `get`/`set`/`delete` against an actual `cache.app`-equivalent PSR-6 pool (filesystem or array adapter, same spirit as `SpikeIntegrationTest`'s real MariaDB), rather than a mocked `CacheItemPoolInterface`. This validates `getItem`/`save`/`deleteItem` "dans le flux réel" per the ticket, without introducing any new code on a live request path that gates authentication for every PayPlug gateway.

### 3. `PayplugConfigurationRepository` — real integration test, no production call site

Same treatment as `PayplugTokenCache`: add a real integration test that constructs `SyliusConfigurationRepository` against an actual `GatewayConfigInterface` on a real `PaymentMethod` fixture persisted via Doctrine (rather than a mock), validating `get`/`set`/`getClientId`/`getClientSecret` against real Sylius config storage. No change to `PayPlugApiClientFactory` or `UnifiedAuthenticationController`.

`getPublicKeyId()`/`getPublicKeyValue()` read from two new config array keys, `public_key_id` and `public_key_value`, added alongside the existing `live_client`/`test_client` keys, defaulting to an empty string when absent. Nothing in production writes these keys yet — this is implementable end-to-end and ready for whenever Hosted Fields lands, documented as a friction note rather than worked around or blocked on.

### 4. `IPaymentRepository` — unchanged

`SyliusPaymentRepository`, `Entity/PayplugOperation.php`, and the `Version20260720100000` migration stay exactly as they are: an unwired sketch, correctly out of scope per the ticket.

### 5. Documentation

Update `src/Spike/VALIDATION.md` to reflect the new verdict per contract: which ones are now genuinely wired into production vs. validated only through real-infrastructure tests, and the two friction notes (`public_key_id`/`public_key_value` have no writer yet; `IOrderStateMutator`/`IConfigurationRepository` production analogs exist as more than one non-unified implementation, which this ticket does not consolidate).

## Testing / regression safety

- Existing PHPUnit + Behat suites must stay green after the changes.
- The one production code change (the additive `PayplugOrderStateMutator` call in `NotifyPaymentRequestHandler`) is guarded by the existing `can()` check, so it cannot alter current transition behavior — only observe/replay it against an already-applied transition.
- New real-infrastructure tests for `PayplugTokenCache` and `PayplugConfigurationRepository` are added, not replacing the existing unit-mocked spike tests.

## Out of scope

- Unifying `PaymentTransitionApplier` and `PaymentStateResolver` into a single `IOrderStateMutator`-backed implementation.
- Any production wiring of `IPaymentRepository`.
- Building Hosted Fields / any actual consumer of `public_key_id`/`public_key_value`.
