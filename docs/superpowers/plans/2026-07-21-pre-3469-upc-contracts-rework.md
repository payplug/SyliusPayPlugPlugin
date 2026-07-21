# PRE-3469 UPC Contracts Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote three of the four PRE-3469 spike contracts (`IOrderStateMutator`, `ITokenCache`, `IConfigurationRepository`) from isolated `src/Spike/` skeletons to real production classes — `PayplugOrderStateMutator`, `PayplugTokenCache`, `PayplugConfigurationRepository` — per the ticket's updated expected results, while keeping `IPaymentRepository` untouched and out of scope, and without regressing existing plugin behavior.

**Architecture:** Move the three classes' existing, already-tested logic out of `src/Spike/` into real production namespaces (`src/PaymentProcessing/`, `src/TokenCache/`, `src/ConfigurationRepository/`), fixing `PayplugConfigurationRepository`'s public-key getters to default to an empty string instead of throwing. Wire `PayplugOrderStateMutator` additively (real call, `can()`-guarded, exception-swallowed) into `NotifyPaymentRequestHandler`. Promote `payplug/unified-plugin-core` from `require-dev` to `require` in `composer.json`, since a production class (`NotifyPaymentRequestHandler`, always loaded) will now reference its types — without this, a merchant's real `composer install --no-dev` would hit a fatal "interface not found" the moment the container tries to autowire the new dependency.

**Tech Stack:** PHP 8.2, Symfony 7.3, Sylius 2.0 (Symfony Workflow via `Sylius\Abstraction\StateMachine\StateMachineInterface`), Doctrine ORM, PSR-6 cache (`Psr\Cache\CacheItemPoolInterface`, aliased by FrameworkBundle to `cache.app`), PHPUnit 10, `payplug/unified-plugin-core` (VCS dependency).

## Global Constraints

- PHP floor: 8.2 (per `composer.json` / ticket context).
- Sylius: `sylius/sylius` `^2.0`.
- `payplug/unified-plugin-core` stays pinned to `dev-develop` (VCS repository `https://github.com/payplug/unified-plugin-core.git`) — only its `require`/`require-dev` placement changes, not the version constraint.
- Existing PHPUnit suite (`vendor/bin/phpunit`, `phpunit.xml.dist` default run, which excludes `tests/PHPUnit/Spike/SpikeIntegrationTest.php`) must stay green after every task.
- No behavioral change to `PaymentTransitionApplier`, `PaymentStateResolver`, or `StatusPaymentRequestHandler` — this ticket does not unify or touch the pre-existing dual state-transition paths.
- `src/Spike/SyliusPaymentRepository.php`, `src/Spike/Entity/PayplugOperation.php`, and `migrations/Version20260720100000.php` are out of scope and must not be modified.
- Every new/moved file must pass the existing `ruleset/phpstan-baseline.neon`-gated static analysis without new baseline entries (fix real issues instead of suppressing them).

---

## Task 1: Promote `payplug/unified-plugin-core` to a real production dependency

**Files:**
- Modify: `composer.json`

**Interfaces:**
- Produces: `payplug/unified-plugin-core` becomes resolvable under `composer install` (with or without `--no-dev`), so later tasks can safely reference `PayplugUnifiedCore\Contracts\IOrderStateMutator` etc. from always-loaded production classes.

- [ ] **Step 1: Move the dependency from `require-dev` to `require`**

In `composer.json`, remove this line from the `require-dev` block:

```json
"payplug/unified-plugin-core": "dev-develop",
```

And add it to the `require` block (keeping the existing `payplug/payplug-php` entry as-is), so `require` reads:

```json
"require": {
    "payplug/payplug-php": "^4.0",
    "payplug/unified-plugin-core": "dev-develop"
},
```

- [ ] **Step 2: Validate the manifest**

Run: `composer validate --no-check-publish --strict`
Expected: `./composer.json is valid`

- [ ] **Step 3: Confirm the package still resolves**

Run: `composer show payplug/unified-plugin-core`
Expected: prints the installed package info (version `dev-develop`, source URL `https://github.com/payplug/unified-plugin-core.git`) without error — confirms the existing local `vendor/` lock is still consistent with the manifest change (a full `composer install --no-dev` re-fetch requires GitHub access and is a manual follow-up for whoever runs CI/deploys this branch, not something to attempt in a sandboxed run).

- [ ] **Step 4: Commit**

```bash
git add composer.json
git commit -m "PRE-3469: Promote unified-plugin-core to a real dependency

NotifyPaymentRequestHandler (task 5) will reference UPC contract types
directly; leaving the package require-dev-only would fatal-error the
container the moment a merchant's production composer install --no-dev
tries to autowire the new dependency."
```

---

## Task 2: Promote `SyliusOrderStateMutator` → `PayplugOrderStateMutator`

**Files:**
- Create: `src/PaymentProcessing/PayplugOrderStateMutator.php`
- Create: `tests/PHPUnit/PaymentProcessing/PayplugOrderStateMutatorTest.php`
- Delete: `src/Spike/SyliusOrderStateMutator.php`
- Delete: `tests/PHPUnit/Spike/SyliusOrderStateMutatorTest.php`
- Modify: `tests/PHPUnit/Spike/SpikeIntegrationTest.php`

**Interfaces:**
- Consumes: `Sylius\Component\Core\Repository\OrderRepositoryInterface::findOrderById(string): ?OrderInterface`, `Sylius\Abstraction\StateMachine\StateMachineInterface::can()/apply()`, `Doctrine\ORM\EntityManagerInterface::flush()`.
- Produces: `PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PayplugOrderStateMutator` implementing `PayplugUnifiedCore\Contracts\IOrderStateMutator`, constructor `(OrderRepositoryInterface $orderRepository, StateMachineInterface $stateMachine, EntityManagerInterface $entityManager)`, method `apply(string $orderId, string $outcome): void`. Task 5 depends on this exact class/constructor.

- [ ] **Step 1: Write the new test file**

Create `tests/PHPUnit/PaymentProcessing/PayplugOrderStateMutatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PayplugOrderStateMutator;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PayplugOrderStateMutatorTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    private StateMachineInterface&MockObject $stateMachine;

    private EntityManagerInterface&MockObject $entityManager;

    private PayplugOrderStateMutator $mutator;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->mutator = new PayplugOrderStateMutator($this->orderRepository, $this->stateMachine, $this->entityManager);
    }

    /**
     * THREE_DS_PENDING must stay a no-op — the order remains `new` until the async webhook
     * resolves it. Neither the order repository nor the state machine should even be touched.
     */
    public function testApply_threeDsPending_isNoOp(): void
    {
        $this->orderRepository->expects(self::never())->method('findOrderById');
        $this->stateMachine->expects(self::never())->method('apply');

        $this->mutator->apply('order-1', PaymentOutcome::THREE_DS_PENDING);
    }

    /**
     * @dataProvider provideMappedOutcomes
     */
    public function testApply_mappedOutcome_appliesExpectedTransition(string $outcome, string $expectedTransition): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $this->orderRepository->method('findOrderById')->with('order-1')->willReturn($order);
        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, $expectedTransition)->willReturn(true);
        $this->stateMachine->expects(self::once())->method('apply')->with($payment, PaymentTransitions::GRAPH, $expectedTransition);
        $this->entityManager->expects(self::once())->method('flush');

        $this->mutator->apply('order-1', $outcome);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideMappedOutcomes(): array
    {
        return [
            'paid' => [PaymentOutcome::PAID, PaymentTransitions::TRANSITION_COMPLETE],
            'authorized' => [PaymentOutcome::AUTHORIZED, PaymentTransitions::TRANSITION_AUTHORIZE],
            'capture_required' => [PaymentOutcome::CAPTURE_REQUIRED, PaymentTransitions::TRANSITION_PROCESS],
            'refunded' => [PaymentOutcome::REFUNDED, PaymentTransitions::TRANSITION_REFUND],
            'failed' => [PaymentOutcome::FAILED, PaymentTransitions::TRANSITION_FAIL],
        ];
    }

    /**
     * A retried webhook landing on a payment already past this transition is a silent no-op,
     * not a thrown workflow exception — mirrors the plugin's existing PaymentStateResolver.
     */
    public function testApply_transitionNotAvailable_isSilentNoOp(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $this->orderRepository->method('findOrderById')->willReturn($order);
        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->mutator->apply('order-1', PaymentOutcome::PAID);
    }

    public function testApply_orderNotFound_throwsPaymentNotFoundException(): void
    {
        $this->orderRepository->method('findOrderById')->with('missing-order')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->mutator->apply('missing-order', PaymentOutcome::PAID);
    }

    public function testApply_orderHasNoPayment_throwsPaymentNotFoundException(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn(null);
        $this->orderRepository->method('findOrderById')->willReturn($order);

        $this->expectException(PaymentNotFoundException::class);

        $this->mutator->apply('order-1', PaymentOutcome::PAID);
    }

    public function testApply_unmappedOutcome_throwsInvalidArgumentException(): void
    {
        $this->orderRepository->expects(self::never())->method('findOrderById');

        $this->expectException(\InvalidArgumentException::class);

        $this->mutator->apply('order-1', 'not_a_real_outcome');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/PHPUnit/PaymentProcessing/PayplugOrderStateMutatorTest.php`
Expected: FAIL — `Class "PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PayplugOrderStateMutator" not found`

- [ ] **Step 3: Create the production class**

Create `src/PaymentProcessing/PayplugOrderStateMutator.php`:

```php
<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Doctrine\ORM\EntityManagerInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\PaymentOutcome;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * PRE-3469: real implementation of IOrderStateMutator against Sylius's Symfony
 * Workflow-backed payment state machine. Wired for real (additively, can()-guarded) from
 * NotifyPaymentRequestHandler — see there for why the call cannot regress the existing
 * PaymentTransitionApplier-driven transition.
 *
 * The state machine's apply() only mutates the in-memory `state` property
 * (marking_store: method) — it never flushes on its own, exactly like the plugin's existing
 * PaymentStateResolver::resolve(), hence the explicit flush() below.
 */
final class PayplugOrderStateMutator implements IOrderStateMutator
{
    /**
     * THREE_DS_PENDING is deliberately absent: per PRE-3469, the order must stay in its
     * current state (`new`) until the async webhook resolves it to PAID/AUTHORIZED/FAILED —
     * there is no Symfony Workflow transition for "awaiting 3DS".
     *
     * @var array<string, string>
     */
    private const OUTCOME_TO_TRANSITION = [
        PaymentOutcome::PAID => PaymentTransitions::TRANSITION_COMPLETE,
        PaymentOutcome::AUTHORIZED => PaymentTransitions::TRANSITION_AUTHORIZE,
        PaymentOutcome::CAPTURE_REQUIRED => PaymentTransitions::TRANSITION_PROCESS,
        PaymentOutcome::REFUNDED => PaymentTransitions::TRANSITION_REFUND,
        PaymentOutcome::FAILED => PaymentTransitions::TRANSITION_FAIL,
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(string $orderId, string $outcome): void
    {
        if (PaymentOutcome::THREE_DS_PENDING === $outcome) {
            return;
        }

        $transition = self::OUTCOME_TO_TRANSITION[$outcome] ?? null;
        if (null === $transition) {
            throw new \InvalidArgumentException(\sprintf('No Symfony Workflow transition mapped for PaymentOutcome "%s".', $outcome));
        }

        $order = $this->orderRepository->findOrderById($orderId);
        if (null === $order) {
            throw new PaymentNotFoundException(\sprintf('No order found for id "%s".', $orderId));
        }

        $payment = $order->getLastPayment();
        if (null === $payment) {
            throw new PaymentNotFoundException(\sprintf('Order "%s" has no payment to mutate.', $orderId));
        }

        // Guarded exactly like the plugin's existing PaymentStateResolver::applyTransition():
        // an out-of-order webhook retry landing on a payment already past this transition is a
        // silent no-op rather than a thrown workflow exception.
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
            $this->entityManager->flush();
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PHPUnit/PaymentProcessing/PayplugOrderStateMutatorTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 5: Delete the promoted spike files**

```bash
git rm src/Spike/SyliusOrderStateMutator.php tests/PHPUnit/Spike/SyliusOrderStateMutatorTest.php
```

- [ ] **Step 6: Update `SpikeIntegrationTest.php`'s two OrderStateMutator methods**

In `tests/PHPUnit/Spike/SpikeIntegrationTest.php`, replace the import:

```php
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusOrderStateMutator;
```

with:

```php
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PayplugOrderStateMutator;
```

and replace both occurrences of `new SyliusOrderStateMutator(` with `new PayplugOrderStateMutator(` (in `testOrderStateMutator_realStateMachine_transitionsPaymentToCompleted` and `testOrderStateMutator_threeDsPending_leavesRealPaymentUntouched`).

- [ ] **Step 7: Confirm no other references to the old class remain**

Run: `grep -rn "SyliusOrderStateMutator" src/ tests/`
Expected: no output.

- [ ] **Step 8: Run the full default suite**

Run: `vendor/bin/phpunit`
Expected: `OK (...)` — no failures, no errors.

- [ ] **Step 9: Commit**

```bash
git add src/PaymentProcessing/PayplugOrderStateMutator.php tests/PHPUnit/PaymentProcessing/PayplugOrderStateMutatorTest.php tests/PHPUnit/Spike/SpikeIntegrationTest.php
git commit -m "PRE-3469: Promote SyliusOrderStateMutator to PayplugOrderStateMutator

Moves the IOrderStateMutator skeleton out of src/Spike/ into a real
production namespace, ahead of wiring it into NotifyPaymentRequestHandler."
```

---

## Task 3: Promote `SyliusTokenCache` → `PayplugTokenCache`

**Files:**
- Create: `src/TokenCache/PayplugTokenCache.php`
- Create: `tests/PHPUnit/TokenCache/PayplugTokenCacheTest.php`
- Delete: `src/Spike/SyliusTokenCache.php`
- Delete: `tests/PHPUnit/Spike/SyliusTokenCacheTest.php`
- Modify: `tests/PHPUnit/Spike/SpikeIntegrationTest.php`

**Interfaces:**
- Consumes: `Psr\Cache\CacheItemPoolInterface::getItem()/save()/deleteItem()` (autowireable via FrameworkBundle's `Psr\Cache\CacheItemPoolInterface: '@cache.app'` alias — confirmed in `vendor/symfony/framework-bundle/Resources/config/cache.php`).
- Produces: `PayPlug\SyliusPayPlugPlugin\TokenCache\PayplugTokenCache` implementing `PayplugUnifiedCore\Contracts\ITokenCache`, constructor `(CacheItemPoolInterface $cache)`.

- [ ] **Step 1: Write the new test file**

Create `tests/PHPUnit/TokenCache/PayplugTokenCacheTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\TokenCache;

use PayPlug\SyliusPayPlugPlugin\TokenCache\PayplugTokenCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class PayplugTokenCacheTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;

    private PayplugTokenCache $tokenCache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->tokenCache = new PayplugTokenCache($this->cache);
    }

    public function testGet_hit_returnsStoredValue(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn('jwt-value');
        $this->cache->method('getItem')->with('token-key')->willReturn($item);

        self::assertSame('jwt-value', $this->tokenCache->get('token-key'));
    }

    public function testGet_miss_returnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($item);

        self::assertNull($this->tokenCache->get('token-key'));
    }

    /**
     * PSR-6's CacheItemInterface::get() is typed mixed — defend against a non-string value
     * ever having been stored under this key (e.g. by another caller of the same pool).
     */
    public function testGet_hitWithNonStringValue_returnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(42);
        $this->cache->method('getItem')->willReturn($item);

        self::assertNull($this->tokenCache->get('token-key'));
    }

    public function testSet_storesValueWithTtlAndSaves(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with('jwt-value');
        $item->expects(self::once())->method('expiresAfter')->with(298);
        $this->cache->method('getItem')->with('token-key')->willReturn($item);
        $this->cache->expects(self::once())->method('save')->with($item);

        $this->tokenCache->set('token-key', 'jwt-value', 298);
    }

    public function testDelete_removesItem(): void
    {
        $this->cache->expects(self::once())->method('deleteItem')->with('token-key');

        $this->tokenCache->delete('token-key');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/PHPUnit/TokenCache/PayplugTokenCacheTest.php`
Expected: FAIL — `Class "PayPlug\SyliusPayPlugPlugin\TokenCache\PayplugTokenCache" not found`

- [ ] **Step 3: Create the production class**

Create `src/TokenCache/PayplugTokenCache.php`:

```php
<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\TokenCache;

use PayplugUnifiedCore\Contracts\ITokenCache;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PRE-3469: real implementation of ITokenCache, targeting the OAuth JWT access-token cache
 * (per the ticket's clarified wording, and the interface's own docblock) — not saved-card
 * storage, which is a permanent Doctrine entity (Card) with no cache involved.
 *
 * get/set/delete map 1:1 onto CacheItemPoolInterface's getItem/save/deleteItem. Sylius's
 * default `cache.app` pool (Symfony\Component\Cache\Adapter\AdapterInterface, autowired here
 * via FrameworkBundle's built-in CacheItemPoolInterface -> cache.app alias) already satisfies
 * CacheItemPoolInterface, regardless of whether that pool is backed by the filesystem, APCu, or
 * Redis in a given deployment. This class is validated by a real-infrastructure integration
 * test (see SpikeIntegrationTest) rather than wired into a live request path: the one existing
 * production caller of an OAuth token cache, PayPlugApiClientFactory::getTokenForGatewayConfig(),
 * gates authentication for every PayPlug gateway — replacing its inline caching logic here would
 * carry more regression risk than this ticket's validation goal justifies.
 */
final class PayplugTokenCache implements ITokenCache
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function get(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    public function delete(string $key): void
    {
        $this->cache->deleteItem($key);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PHPUnit/TokenCache/PayplugTokenCacheTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Delete the promoted spike files**

```bash
git rm src/Spike/SyliusTokenCache.php tests/PHPUnit/Spike/SyliusTokenCacheTest.php
```

- [ ] **Step 6: Update `SpikeIntegrationTest.php`'s TokenCache method**

In `tests/PHPUnit/Spike/SpikeIntegrationTest.php`, replace the import:

```php
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusTokenCache;
```

with:

```php
use PayPlug\SyliusPayPlugPlugin\TokenCache\PayplugTokenCache;
```

and in `testTokenCache_realCachePool_roundTripsThroughRealAdapter`, replace `new SyliusTokenCache($cachePool)` with `new PayplugTokenCache($cachePool)`.

- [ ] **Step 7: Confirm no other references to the old class remain**

Run: `grep -rn "SyliusTokenCache" src/ tests/`
Expected: no output.

- [ ] **Step 8: Run the full default suite**

Run: `vendor/bin/phpunit`
Expected: `OK (...)` — no failures, no errors.

- [ ] **Step 9: Commit**

```bash
git add src/TokenCache/PayplugTokenCache.php tests/PHPUnit/TokenCache/PayplugTokenCacheTest.php tests/PHPUnit/Spike/SpikeIntegrationTest.php
git commit -m "PRE-3469: Promote SyliusTokenCache to PayplugTokenCache

Moves the ITokenCache skeleton out of src/Spike/ into a real production
namespace. Stays validated by a real-infrastructure integration test only
(see SpikeIntegrationTest) — no production call site, since its one real
analog (PayPlugApiClientFactory's OAuth token cache) gates authentication
for every gateway and isn't worth the regression risk of replacing here."
```

---

## Task 4: Promote `SyliusConfigurationRepository` → `PayplugConfigurationRepository`, fix public-key getters

**Files:**
- Create: `src/ConfigurationRepository/PayplugConfigurationRepository.php`
- Create: `tests/PHPUnit/ConfigurationRepository/PayplugConfigurationRepositoryTest.php`
- Delete: `src/Spike/SyliusConfigurationRepository.php`
- Delete: `tests/PHPUnit/Spike/SyliusConfigurationRepositoryTest.php`
- Modify: `tests/PHPUnit/Spike/SpikeIntegrationTest.php`

**Interfaces:**
- Consumes: `Sylius\Component\Payment\Model\GatewayConfigInterface::getConfig()/setConfig()/getFactoryName()`.
- Produces: `PayPlug\SyliusPayPlugPlugin\ConfigurationRepository\PayplugConfigurationRepository` implementing `PayplugUnifiedCore\Contracts\IConfigurationRepository`, constructor `(GatewayConfigInterface $gatewayConfig)`. `getClientId()`/`getClientSecret()` throw `PayplugUnifiedCore\Exceptions\ApiException` when missing (unchanged from the spike). `getPublicKeyId()`/`getPublicKeyValue()` now return `''` when missing instead of throwing — no production writer for these two keys exists yet.

- [ ] **Step 1: Write the new test file**

Create `tests/PHPUnit/ConfigurationRepository/PayplugConfigurationRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\ConfigurationRepository;

use PayPlug\SyliusPayPlugPlugin\ConfigurationRepository\PayplugConfigurationRepository;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

final class PayplugConfigurationRepositoryTest extends TestCase
{
    private GatewayConfigInterface&MockObject $gatewayConfig;

    protected function setUp(): void
    {
        $this->gatewayConfig = $this->createMock(GatewayConfigInterface::class);
    }

    public function testGet_liveMode_readsFromLiveClientScope(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'live-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        self::assertSame('live-id', $this->repository()->get('client_id'));
    }

    public function testGet_testMode_readsFromTestClientScope(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'live_client' => ['client_id' => 'live-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        self::assertSame('test-id', $this->repository()->get('client_id'));
    }

    public function testGet_missingKey_returnsNull(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true, 'live_client' => []]);

        self::assertNull($this->repository()->get('client_id'));
    }

    public function testSet_writesIntoActiveScopeAndPersistsWholeConfig(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'old-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        $this->gatewayConfig->expects(self::once())->method('setConfig')->with([
            'live' => true,
            'live_client' => ['client_id' => 'new-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        $this->repository()->set('client_id', 'new-id');
    }

    /**
     * @dataProvider provideRequiredCredentialGetters
     */
    public function testRequiredCredentialGetters_present_returnValue(string $method, string $configKey): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => [$configKey => 'the-value'],
        ]);

        self::assertSame('the-value', $this->repository()->{$method}());
    }

    /**
     * @dataProvider provideRequiredCredentialGetters
     *
     * The exception message must name the missing key and the factory, never a credential
     * value — there is nothing sensitive to redact here since the value is simply absent, but
     * this locks the message shape so a future edit can't start interpolating $value instead.
     */
    public function testRequiredCredentialGetters_missing_throwsApiExceptionWithoutLeakingSecrets(
        string $method,
        string $configKey,
    ): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true, 'live_client' => []]);
        $this->gatewayConfig->method('getFactoryName')->willReturn('payplug');

        try {
            $this->repository()->{$method}();
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $exception) {
            self::assertSame(\sprintf('Missing "%s" in gateway configuration "payplug".', $configKey), $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideRequiredCredentialGetters(): array
    {
        return [
            'clientId' => ['getClientId', 'client_id'],
            'clientSecret' => ['getClientSecret', 'client_secret'],
        ];
    }

    /**
     * @dataProvider provideOptionalCredentialGetters
     */
    public function testOptionalCredentialGetters_present_returnValue(string $method, string $configKey): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => [$configKey => 'the-value'],
        ]);

        self::assertSame('the-value', $this->repository()->{$method}());
    }

    /**
     * getPublicKeyId()/getPublicKeyValue() have no production writer yet (Hosted Fields isn't
     * built) — unlike client_id/client_secret, a missing value must not throw, or the contract
     * would be unimplementable until a future ticket adds that writer.
     *
     * @dataProvider provideOptionalCredentialGetters
     */
    public function testOptionalCredentialGetters_missing_returnEmptyString(string $method): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true, 'live_client' => []]);

        self::assertSame('', $this->repository()->{$method}());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideOptionalCredentialGetters(): array
    {
        return [
            'publicKeyId' => ['getPublicKeyId', 'public_key_id'],
            'publicKeyValue' => ['getPublicKeyValue', 'public_key_value'],
        ];
    }

    private function repository(): PayplugConfigurationRepository
    {
        return new PayplugConfigurationRepository($this->gatewayConfig);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/PHPUnit/ConfigurationRepository/PayplugConfigurationRepositoryTest.php`
Expected: FAIL — `Class "PayPlug\SyliusPayPlugPlugin\ConfigurationRepository\PayplugConfigurationRepository" not found`

- [ ] **Step 3: Create the production class**

Create `src/ConfigurationRepository/PayplugConfigurationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ConfigurationRepository;

use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Exceptions\ApiException;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

/**
 * PRE-3469: real implementation of IConfigurationRepository against Sylius's
 * GatewayConfigInterface.
 *
 * IConfigurationRepository assumes one flat set of credentials, but Sylius scopes gateway
 * config per PaymentMethod *and* per live/test mode (`config['live_client']` vs
 * `config['test_client']`, selected by `config['live']`, exactly as PayPlugApiClientFactory
 * already does). So a single PayplugConfigurationRepository instance has to be constructed per
 * GatewayConfigInterface (i.e. per PaymentMethod) rather than shared as one repository-wide
 * service — a factory, not a singleton. Not blocking, but worth flagging if the Unified API
 * client this feeds ever assumes one repository == one merchant.
 *
 * getPublicKeyId()/getPublicKeyValue() default to an empty string rather than throwing: unlike
 * client_id/client_secret, no production code writes public_key_id/public_key_value yet
 * (Hosted Fields isn't built) — requiring them would make the contract unimplementable until a
 * future ticket adds that writer.
 *
 * Sylius already ships an (experimental) GatewayConfigEncrypter that transparently encrypts the
 * whole `getConfig()` array at rest (Sylius\Component\Payment\Encryption) — if wired up,
 * CLIENT_SECRET benefits from that for free. What this class must still guarantee on its own is
 * that a *decrypted* secret never leaks into a log line or exception message, which is why
 * requireString() below only ever interpolates the config *key name*, never its value.
 */
final class PayplugConfigurationRepository implements IConfigurationRepository
{
    public function __construct(private readonly GatewayConfigInterface $gatewayConfig)
    {
    }

    public function get(string $key): ?string
    {
        $client = $this->activeClientConfig();
        $value = $client[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $config = $this->gatewayConfig->getConfig();
        $scope = $this->activeScope($config);
        $client = $config[$scope] ?? [];
        if (!\is_array($client)) {
            $client = [];
        }
        $client[$key] = $value;
        $config[$scope] = $client;

        // Persisting $config is the caller's responsibility (Doctrine flush), same as every
        // other GatewayConfigInterface mutation in this plugin (see UnifiedAuthenticationController).
        $this->gatewayConfig->setConfig($config);
    }

    public function getClientId(): string
    {
        return $this->requireString('client_id');
    }

    public function getClientSecret(): string
    {
        return $this->requireString('client_secret');
    }

    public function getPublicKeyId(): string
    {
        return $this->get('public_key_id') ?? '';
    }

    public function getPublicKeyValue(): string
    {
        return $this->get('public_key_value') ?? '';
    }

    private function requireString(string $key): string
    {
        $value = $this->get($key);
        if (null === $value || '' === $value) {
            // Never interpolate the resolved *value* here, only the key name and factory name.
            throw new ApiException(\sprintf(
                'Missing "%s" in gateway configuration "%s".',
                $key,
                $this->gatewayConfig->getFactoryName() ?? 'unknown',
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function activeClientConfig(): array
    {
        $config = $this->gatewayConfig->getConfig();
        $client = $config[$this->activeScope($config)] ?? [];
        if (!\is_array($client)) {
            return [];
        }

        /** @var array<string, mixed> $typedClient */
        $typedClient = $client;

        return $typedClient;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function activeScope(array $config): string
    {
        return true === ($config['live'] ?? false) ? 'live_client' : 'test_client';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PHPUnit/ConfigurationRepository/PayplugConfigurationRepositoryTest.php`
Expected: `OK (9 tests, ...)`

- [ ] **Step 5: Delete the promoted spike files**

```bash
git rm src/Spike/SyliusConfigurationRepository.php tests/PHPUnit/Spike/SyliusConfigurationRepositoryTest.php
```

- [ ] **Step 6: Update `SpikeIntegrationTest.php`'s ConfigurationRepository method**

In `tests/PHPUnit/Spike/SpikeIntegrationTest.php`, replace the import:

```php
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusConfigurationRepository;
```

with:

```php
use PayPlug\SyliusPayPlugPlugin\ConfigurationRepository\PayplugConfigurationRepository;
```

and in `testConfigurationRepository_realGatewayConfig_survivesADoctrineRoundTrip`, replace both occurrences of `new SyliusConfigurationRepository(` with `new PayplugConfigurationRepository(`.

- [ ] **Step 7: Confirm no other references to the old class remain**

Run: `grep -rn "SyliusConfigurationRepository" src/ tests/`
Expected: no output.

- [ ] **Step 8: Run the full default suite**

Run: `vendor/bin/phpunit`
Expected: `OK (...)` — no failures, no errors.

- [ ] **Step 9: Commit**

```bash
git add src/ConfigurationRepository/PayplugConfigurationRepository.php tests/PHPUnit/ConfigurationRepository/PayplugConfigurationRepositoryTest.php tests/PHPUnit/Spike/SpikeIntegrationTest.php
git commit -m "PRE-3469: Promote SyliusConfigurationRepository to PayplugConfigurationRepository

Moves the IConfigurationRepository skeleton out of src/Spike/ into a real
production namespace. getPublicKeyId()/getPublicKeyValue() now default to
an empty string instead of throwing, since no production code writes
public_key_id/public_key_value yet (Hosted Fields isn't built) — documented
as friction rather than blocked on."
```

---

## Task 5: Wire `PayplugOrderStateMutator` into `NotifyPaymentRequestHandler`

**Files:**
- Modify: `src/Command/Handler/NotifyPaymentRequestHandler.php`
- Create: `tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`

**Interfaces:**
- Consumes: `PayplugUnifiedCore\Contracts\IOrderStateMutator::apply(string, string): void` — depend on this interface, NOT the concrete `PayplugOrderStateMutator` class. `PayplugOrderStateMutator` (Task 2) is declared `final`, which PHPUnit cannot mock by subclassing; depending on the interface (its only implementation in this codebase) sidesteps that entirely and is autowireable identically. Also uses `PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface::STATUS_CAPTURED/STATUS_AUTHORIZED/FAILED` (existing constants) and `PayplugUnifiedCore\Models\PaymentOutcome::PAID/AUTHORIZED/FAILED`.
- Produces: no new public interface — this is the real production call site the ticket requires.

- [ ] **Step 1: Write the failing tests**

Create `tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use Payplug\Resource\Payment as PayplugResourcePayment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\NotifyPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PaymentNotificationHandler&MockObject $paymentNotificationHandler;

    private RefundNotificationHandler&MockObject $refundNotificationHandler;

    private PaymentTransitionApplier&MockObject $paymentTransitionApplier;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private LoggerInterface&MockObject $logger;

    private NotifyPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->paymentNotificationHandler = $this->createMock(PaymentNotificationHandler::class);
        $this->refundNotificationHandler = $this->createMock(RefundNotificationHandler::class);
        $this->paymentTransitionApplier = $this->createMock(PaymentTransitionApplier::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new NotifyPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->apiClientFactory,
            $this->paymentNotificationHandler,
            $this->refundNotificationHandler,
            $this->paymentTransitionApplier,
            $this->orderStateMutator,
            $this->logger,
        );
    }

    public function testInvoke_knownStatus_callsOrderStateMutatorWithMappedOutcome(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CAPTURED, 42);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_unknownStatus_doesNotCallOrderStateMutator(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CANCELED, 42);

        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_orderStateMutatorThrows_isCaughtAndDoesNotPreventCompletion(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CAPTURED, 42);

        $this->orderStateMutator->method('apply')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects(self::once())->method('warning');

        // The outer flow must still complete normally: TRANSITION_COMPLETE, not TRANSITION_FAIL.
        $this->stateMachine->expects(self::once())->method('apply')->with(
            self::isInstanceOf(PaymentRequestInterface::class),
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_paymentAlreadyCompleted_shortCircuitsAndSkipsOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_COMPLETED);
        $method = $this->createMock(PaymentMethodInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '{}']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $resource = $this->createMock(PayplugResourcePayment::class);
        $client->method('treat')->willReturn($resource);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);

        $this->paymentTransitionApplier->expects(self::never())->method('apply');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_invalidPayload_failsWithoutCallingOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $paymentRequest->expects(self::once())->method('setResponseData');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    private function prepareNormalFlow(string $status, int $orderId): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn($orderId);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_NEW);
        $payment->method('getDetails')->willReturn(['status' => $status]);
        $payment->method('getOrder')->willReturn($order);
        $method = $this->createMock(PaymentMethodInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '{}']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $resource = $this->createMock(PayplugResourcePayment::class);
        $client->method('treat')->willReturn($resource);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`
Expected: FAIL — constructor call error (`Too many arguments` / `Unknown named parameter`), since `NotifyPaymentRequestHandler` doesn't yet accept `PayplugOrderStateMutator`/`LoggerInterface`.

- [ ] **Step 3: Modify `NotifyPaymentRequestHandler`**

Replace the full contents of `src/Command/Handler/NotifyPaymentRequestHandler.php`:

```php
<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Models\PaymentOutcome;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyPaymentRequestHandler
{
    /**
     * PRE-3469: additive translation from the PayPlug status vocabulary (the same one
     * PaymentTransitionApplier already maps from) to UPC's PaymentOutcome vocabulary, for the
     * real IOrderStateMutator call below. Statuses with no PaymentOutcome equivalent
     * (e.g. STATUS_ABORTED/STATUS_CANCELED*, which PaymentTransitionApplier maps to
     * TRANSITION_CANCEL) are intentionally absent here — they're skipped, never force-mapped.
     *
     * @var array<string, string>
     */
    private const STATUS_TO_OUTCOME = [
        PayPlugApiClientInterface::STATUS_CAPTURED => PaymentOutcome::PAID,
        PayPlugApiClientInterface::STATUS_AUTHORIZED => PaymentOutcome::AUTHORIZED,
        PayPlugApiClientInterface::FAILED => PaymentOutcome::FAILED,
    ];

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private PaymentTransitionApplier $paymentTransitionApplier,
        private IOrderStateMutator $orderStateMutator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(NotifyPaymentRequest $notifyPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($notifyPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();

        try {
            $payload = $paymentRequest->getPayload();
            $content = $payload['http_request']['content'] ?? null; // @phpstan-ignore-line
            if (!is_string($content) || '' === $content) {
                throw new \LogicException('Invalid PayPlug notification payload.');
            }

            $method = $payment->getMethod();
            if (null === $method) {
                throw new \LogicException('Payment method is not set for the payment.');
            }

            $client = $this->apiClientFactory->createForPaymentMethod($method);
            $resource = $client->treat($content);

            if ($resource instanceof Payment && $payment->getState() === PaymentInterface::STATE_COMPLETED) {
                // If the payment is already completed, we do not need to update it again
                $this->stateMachine->apply(
                    $paymentRequest,
                    PaymentRequestTransitions::GRAPH,
                    PaymentRequestTransitions::TRANSITION_COMPLETE,
                );

                return;
            }

            $details = new \ArrayObject($payment->getDetails());
            $this->paymentNotificationHandler->treat($payment, $resource, $details);
            $this->refundNotificationHandler->treat($payment, $resource, $details);

            $payment->setDetails($details->getArrayCopy());
            if ($resource instanceof Payment) {
                $this->paymentTransitionApplier->apply($payment);
                $this->applyOrderStateMutator($payment);
            }

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );
        } catch (\Throwable $e) {
            $paymentRequest->setResponseData([
                'error' => $e->getMessage(),
            ]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );
        }
    }

    /**
     * PRE-3469: additive real call site for PayplugOrderStateMutator. PaymentTransitionApplier
     * has already applied the real transition above by the time this runs, so the mutator's own
     * can()-guard makes this a no-op in the normal case — this exists to prove the contract works
     * against a live webhook event, not to change behavior. Any failure here is caught and
     * logged, never allowed to affect the primary notification flow above.
     */
    private function applyOrderStateMutator(PaymentInterface $payment): void
    {
        $details = $payment->getDetails(); // @phpstan-ignore-line - getDetails() return mixed
        $status = $details['status'] ?? '';
        $outcome = self::STATUS_TO_OUTCOME[$status] ?? null;
        if (null === $outcome) {
            return;
        }

        $order = $payment->getOrder();
        if (null === $order) {
            return;
        }

        try {
            $this->orderStateMutator->apply((string) $order->getId(), $outcome);
        } catch (\Throwable $e) {
            $this->logger->warning('[PayPlug] PayplugOrderStateMutator additive call failed.', [
                'sylius_payment_id' => $payment->getId(),
                'outcome' => $outcome,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Run the full default suite**

Run: `vendor/bin/phpunit`
Expected: `OK (...)` — no failures, no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Command/Handler/NotifyPaymentRequestHandler.php tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php
git commit -m "PRE-3469: Wire PayplugOrderStateMutator into NotifyPaymentRequestHandler

Additive real call site: runs after the existing PaymentTransitionApplier
transition, guarded by the mutator's own can() check (safe no-op once the
transition's already applied) and wrapped in try/catch so any failure is
logged, never allowed to break webhook notification handling."
```

---

## Task 6: Update `src/Spike/VALIDATION.md`

**Files:**
- Modify: `src/Spike/VALIDATION.md`

**Interfaces:**
- None (documentation only).

- [ ] **Step 1: Rewrite the document to reflect the promoted contracts**

Replace the full contents of `src/Spike/VALIDATION.md`:

```markdown
# PRE-3469 — Validation des contrats UPC contre Sylius

`IOrderStateMutator`, `ITokenCache` et `IConfigurationRepository` ont été promus en implémentations
réelles, branchées dans le code de production existant (voir `src/PaymentProcessing/PayplugOrderStateMutator.php`,
`src/TokenCache/PayplugTokenCache.php`, `src/ConfigurationRepository/PayplugConfigurationRepository.php`).
`IPaymentRepository` reste hors scope de ce ticket (lié à un Value Object fortement couplé à
l'API Unifiée, non encore branché sur le plugin) — son squelette de preuve reste dans ce dossier,
voir `SyliusPaymentRepository.php`.

Couverture de tests, 3 niveaux :

- **Unitaire** (`tests/PHPUnit/{PaymentProcessing,TokenCache,ConfigurationRepository}/*Test.php`
  + `tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`) : mocks natifs PHPUnit
  sur chaque collaborateur.
- **Intégration réelle** (`SpikeIntegrationTest.php`, toujours dans ce dossier car c'est le seul
  endroit du plugin qui boote un vrai kernel Sylius pour les tests) : kernel Sylius réellement
  booté, vraie base MariaDB jetable, vrai state machine Symfony Workflow, vrai pool de cache
  PSR-6, vraie persistance Doctrine — aucun mock. Couvre les 4 contrats, y compris
  `IPaymentRepository`.
- Les deux suites tournent avec `composer install`, `vendor/bin/phpunit` une fois la base de
  test créée — voir section Câblage.

**Verdict global : aucune friction bloquante restante.** Une friction bloquante a été trouvée et
corrigée pendant ce rework (voir « Friction bloquante corrigée » ci-dessous), plus deux notes non
bloquantes (aucune ne remet en cause la forme des interfaces).

## Friction bloquante corrigée : `payplug/unified-plugin-core` en `require-dev`

`SyliusOrderStateMutator`/`SyliusTokenCache`/`SyliusConfigurationRepository` (les squelettes
d'origine) implémentaient directement les interfaces UPC depuis `src/Spike/`, avec
`payplug/unified-plugin-core` en `require-dev` uniquement. Tant que ce code restait isolé et
jamais référencé par une classe de production, ça ne posait pas de problème. Mais brancher
`PayplugOrderStateMutator` dans `NotifyPaymentRequestHandler` — une classe systématiquement
chargée, y compris en production — change la donne : un vrai `composer install --no-dev` chez un
marchand n'installerait pas `unified-plugin-core`, et charger une classe qui `implements` une
interface inexistante est une erreur PHP fatale, pas un échec silencieux.

**Fix** : `payplug/unified-plugin-core` est passé en dépendance `require` (toujours pinné sur
`dev-develop`, même repository VCS). Documenté ici plutôt que traité comme un simple détail de
`composer.json`, parce que ça anticipe une partie de ce que PRE-3563 (la vraie dépendance de
production pour l'OAuth) devait poser — voir section Câblage plus bas pour ce qui reste à faire
pour PRE-3563.

## IOrderStateMutator — réel : `PayplugOrderStateMutator.php`

Mapping validé : `PaymentOutcome::PAID/AUTHORIZED/CAPTURE_REQUIRED/REFUNDED/FAILED` →
`PaymentTransitions::TRANSITION_COMPLETE/AUTHORIZE/PROCESS/REFUND/FAIL` sur le graphe
`sylius_payment`, via `StateMachineInterface::can()/apply()` — exactement le pattern déjà utilisé
par `PaymentStateResolver::applyTransition()` dans le plugin actuel.

`THREE_DS_PENDING` confirmé sans transition : l'implémentation retourne immédiatement sans
toucher au state machine, la commande reste `new` jusqu'au webhook — conforme au résultat attendu
du ticket.

**Branché en production** : appel additif dans `NotifyPaymentRequestHandler`, juste après le
`PaymentTransitionApplier::apply($payment)` existant, une fois la transition déjà appliquée par
ce dernier. Le statut PayPlug déjà connu à cet endroit (`$payment->getDetails()['status']`) est
traduit en `PaymentOutcome` (seuls `STATUS_CAPTURED`/`STATUS_AUTHORIZED`/`FAILED` ont un
équivalent — `STATUS_ABORTED`/`STATUS_CANCELED*`, mappés par `PaymentTransitionApplier` sur
`TRANSITION_CANCEL`, n'ont pas d'équivalent `PaymentOutcome` et sont donc volontairement ignorés,
pas forcés). L'appel est protégé par un `try/catch` qui journalise sans jamais propager : la
transition ayant déjà été appliquée par `PaymentTransitionApplier`, le garde-fou `can()` du
mutateur en fait un no-op silencieux dans le cas normal ; ce câblage prouve que le contrat
fonctionne contre un webhook réel sans remplacer ni risquer le flux existant.

**Note (non bloquante)** : le contrat prend un `orderId`, mais la transition Symfony Workflow vit
sur le sous-objet `Payment` de la commande (`Order::getLastPayment()`), pas sur l'`Order`
lui-même. Un hop supplémentaire Order → Payment est donc nécessaire côté adaptateur Sylius — ce
que WooCommerce n'aurait pas besoin de faire. C'est le bon endroit pour cette différence (dans
l'adaptateur, pas dans le contrat CMS-agnostique).

**Note annexe (mécanique, pas conceptuelle)** : le plugin a aujourd'hui deux chemins de
production distincts qui appliquent des transitions Symfony Workflow sur un paiement —
`PaymentTransitionApplier` (webhooks/status, celui utilisé ci-dessus) et `PaymentStateResolver`
(réconciliation CLI, `payplug:update-payment-state`). Ce ticket ne les unifie pas ; ça reste deux
implémentations parallèles du même idiome `can()`/`apply()`.

## ITokenCache — réel : `PayplugTokenCache.php`

Aucune friction. `get`/`set`/`delete` se posent 1:1 sur `Psr\Cache\CacheItemPoolInterface::getItem/
save/deleteItem`, exactement comme le docblock de l'interface le prévoyait déjà. Le pool par
défaut de Sylius (`cache.app`, `Symfony\Component\Cache\Adapter\AdapterInterface`) satisfait déjà
`CacheItemPoolInterface`, aliasé nativement par FrameworkBundle — filesystem, APCu ou Redis selon
le déploiement, sans changement de code côté `PayplugTokenCache`.

**Cible confirmée par le ticket** : `ITokenCache` cible le cache du token JWT OAuth
(authentification via le SDK PayPlug), pas le stockage carte/one-click — la sauvegarde de carte
est une entité Doctrine permanente (`Card`), sans aucun cache impliqué.

**Validé par un test d'intégration réel, pas par un appel de production** : le seul analogue de
production existant, `PayPlugApiClientFactory::getTokenForGatewayConfig()`, met en cache le token
OAuth qui conditionne l'authentification de *toutes* les gateways du plugin. Remplacer sa logique
de cache inline aurait un risque de régression disproportionné par rapport à l'objectif de
validation de ce ticket ; `PayplugTokenCache` reste donc validé par un test d'intégration contre
un vrai pool PSR-6 (`SpikeIntegrationTest::testTokenCache_realCachePool_roundTripsThroughRealAdapter`),
sans point d'entrée de production.

## IConfigurationRepository — réel : `PayplugConfigurationRepository.php`

`GatewayConfigInterface::getConfig()` scope les credentials par `PaymentMethod` *et* par mode
live/test (`config['live_client']` vs `config['test_client']`, sélectionné par `config['live']` —
même pattern que `PayPlugApiClientFactory::getTokenForGatewayConfig()` existant). Une instance de
`PayplugConfigurationRepository` doit donc être construite par `GatewayConfigInterface` (donc par
`PaymentMethod`), pas partagée comme service unique — c'est une factory, pas un singleton. Ne
remet pas en cause le contrat, mais à garder en tête si le futur client Unified API suppose "un
repository = un marchand".

**Point positif** : Sylius expose déjà un `GatewayConfigEncrypter` (expérimental) qui chiffre au
repos l'intégralité du tableau `getConfig()` — si branché, `CLIENT_SECRET` en bénéficie
gratuitement. Ce que `PayplugConfigurationRepository` doit garantir lui-même, c'est qu'un secret
déchiffré ne fuite jamais dans un message de log ou d'exception : `requireString()` n'interpole
jamais que le nom de la clé, jamais sa valeur.

**Friction non bloquante, résolue par un choix explicite** : `getPublicKeyId()`/
`getPublicKeyValue()` n'ont aucun équivalent de production — le plugin n'a aucune notion de clé
publique Hosted Fields aujourd'hui (`grep` sur `public_key`/`PUBLIC_KEY` dans `src/` ne retourne
rien en dehors de ce fichier). Plutôt que de lever une exception comme `getClientId()`/
`getClientSecret()`, ces deux méthodes renvoient une chaîne vide tant qu'aucun code de production
n'écrit ces clés — le contrat reste implémentable de bout en bout, prêt pour quand Hosted Fields
sera construit.

## IPaymentRepository — hors scope, squelette inchangé : `SyliusPaymentRepository.php` + `Entity/PayplugOperation.php`

Hors scope de ce ticket (lié à un Value Object fortement couplé à l'API Unifiée, non encore
branché sur le plugin) — reste un squelette de preuve, inchangé depuis la version précédente de
ce document.

**Constat principal du ticket, confirmé** : le plugin actuel ne stocke pas `OperationData` de
façon normalisée. L'id Payplug est aujourd'hui écrit dans le JSON de `Payment::details` et
retrouvé via `LIKE '%id%'` (`PaymentRepository::findOneByPayPlugPaymentId`). Ça fonctionne pour ce
seul lookup, mais ne peut pas porter `markTreated()`/`isTreated()` (il faut un flag d'idempotence
indexé) ni `getByOperationId()` proprement (il faut une colonne indexée, pas une recherche de
sous-chaîne dans un blob sérialisé).

Le squelette introduit donc une nouvelle table `payplug_operation`, sans aucune dépendance au SDK
`payplug/payplug-php` — juste `OperationData` en entrée/sortie. C'est un vrai changement de schéma
(nouvelle table), pas une extension de l'existant — à traiter comme tel dans le chiffrage du futur
ticket de production.

**Note annexe (mécanique, pas conceptuelle)** : `PayplugOperation` vit hors de `src/Entity/` et
n'est pas un `sylius_resource` dans `config/resources.yaml` (contrairement à `Card` et
`RefundHistory`) — enregistrer un `sylius_resource` a été essayé pour le test d'intégration mais
échoue tant que la classe n'implémente pas `Sylius\Resource\Model\ResourceInterface` (grilles/
formulaires/routes Sylius, inutiles pour cette entité de pure persistance). Le mapping Doctrine
réel utilisé pour le test d'intégration passe donc par un `doctrine.orm.mappings` prepend dans
`PayPlugSyliusPayPlugExtension::prependSpikeDoctrineMapping()` — méthode explicitement marquée
PRE-3469-only, restreinte à `kernel.environment === 'test'` (jamais en prod), à retirer avec
`src/Spike/` si le spike est abandonné.

## Câblage (pour rejouer les tests)

- `composer.json` : `repositories` avec un repository `vcs` vers
  `https://github.com/payplug/unified-plugin-core.git` + `payplug/unified-plugin-core:
  "dev-develop"` désormais en dépendance `require` (voir « Friction bloquante corrigée »
  ci-dessus) — fonctionne pour n'importe qui ayant accès au repo GitHub (même accès que pour ce
  repo), et en CI via l'étape `Composer - Github Auth` déjà configurée dans
  `payplug/template-ci`. Une première version utilisait un repository `path` local
  (`../../unified-plugin-core`) : ça ne marche que sur une machine avec les deux repos clonés
  côte à côte, cassait `composer install` pour tout le monde d'autre — corrigé. Nécessite que le
  pin exact `symfony/polyfill-mbstring: 1.28.0` d'UPC ait été relâché en `^1.28` (corrigé dans
  unified-plugin-core suite à ce spike — il bloquait tout `composer install` aux côtés de
  `sylius/sylius ^2.0`, qui exige `^1.31`). Contrepartie connue : ça ajoute une résolution réseau
  vers GitHub à chaque install (léger coût CI/dev) — pas d'alternative disponible ici (pas de
  Packagist privé configuré dans cet org pour `payplug/unified-plugin-core`, et un repository
  `path` est exclu pour la raison ci-dessus). Ce compromis disparaît quand PRE-3563 posera la
  vraie dépendance de production définitive (OAuth réel contre UPC).
- `phpunit.xml.dist` : `KERNEL_CLASS_PATH` renommé en `KERNEL_CLASS` — la variable que Symfony lit
  réellement pour `KernelTestCase::bootKernel()`. Sans ce fix, aucun test à base de kernel
  (fonctionnel/intégration) n'a jamais pu tourner dans ce repo — un bug de config préexistant,
  invisible tant qu'aucun test de ce type n'existait.
- `tests/TestApplication/.env.test.local` (gitignored) : `DATABASE_URL` pointée vers un conteneur
  MariaDB jetable (`docker run --name payplug-pre3469-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p
  3309:3306 mariadb:latest`) — le MySQL système de la machine refuse `root@127.0.0.1` sans mot de
  passe. `.env.local` seul ne suffit pas : Symfony l'ignore délibérément quand `APP_ENV=test`
  (reproductibilité des tests) — il faut `.env.test.local` spécifiquement.
- Base créée/migrée avec les commandes standard du Makefile (`doctrine:database:create`,
  `doctrine:migration:migrate`, `sylius:payment:generate-key`, `sylius:fixtures:load`) — la table
  `payplug_operation` est créée automatiquement par `migrations/Version20260720100000.php`, plus
  besoin d'aucune étape manuelle.

**Isolation des tests** : `SpikeIntegrationTest` ne tourne dans aucune transaction annulée en fin
de test (pas de `DAMADoctrineTestBundle`). Une première version cherchait un paiement de fixture
déjà à l'état `new` — ça fonctionne une fois, mais une commande réelle ne repasse jamais à `new`
une fois transitionnée, donc ce pool fini s'épuise au fil des ré-exécutions contre la même base
jetable. Corrigé en créant un `Payment` frais sur une commande de fixture existante à chaque test
(`createOrderWithFreshPayment()`) plutôt que d'en chercher un dans un état donné — vérifié stable
sur 3 exécutions consécutives. Une vraie suite (non-spike) voudrait un rollback transactionnel
entre tests plutôt que ce contournement.
```

- [ ] **Step 2: Commit**

```bash
git add src/Spike/VALIDATION.md
git commit -m "PRE-3469: Update VALIDATION.md for the promoted contracts

Documents the require-dev blocking friction found and fixed, and the
production wiring decisions for each promoted contract."
```

---

## Task 7: Full regression pass

**Files:** none (verification only).

- [ ] **Step 1: Run the full default PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: `OK (...)` — no failures, no errors, no risky tests introduced.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors beyond the existing `ruleset/phpstan-baseline.neon`. If new errors appear in the 4 new/moved files, fix them in place — do not add new baseline entries for code this plan just wrote.

- [ ] **Step 3: Confirm no dangling references to the retired Spike class names**

Run: `grep -rn "SyliusOrderStateMutator\|SyliusTokenCache\|SyliusConfigurationRepository" .`
Expected: no output (excluding this plan document and the design spec, which reference the old names historically).

- [ ] **Step 4: Confirm `src/Spike/` now contains only the out-of-scope `IPaymentRepository` sketch**

Run: `ls src/Spike/`
Expected: `Entity  SyliusPaymentRepository.php  VALIDATION.md`

- [ ] **Step 5: Note the manual real-infrastructure verification step**

`tests/PHPUnit/Spike/SpikeIntegrationTest.php` stays excluded from the default `phpunit.xml.dist` run (it needs a provisioned MariaDB test database — see its own docblock and the Câblage section of `VALIDATION.md`). Before merging, run it manually against a real database to confirm the renamed classes (`PayplugOrderStateMutator`, `PayplugTokenCache`, `PayplugConfigurationRepository`) still round-trip correctly:

Run: `vendor/bin/phpunit tests/PHPUnit/Spike/SpikeIntegrationTest.php`
Expected: `OK (4 tests, ...)` — requires the DB setup described in `VALIDATION.md`'s Câblage section; this is a manual step for whoever has that database provisioned, not something to attempt in a sandboxed run without one.

- [ ] **Step 6: Run Behat if configured and reachable**

Run: `vendor/bin/behat`
Expected: `OK (...)` — if Behat requires infrastructure not available in the current environment (e.g. a browser driver or fixtures database), document that as a manual pre-merge step rather than skip verification silently.
