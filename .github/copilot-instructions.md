# Copilot Instructions

These instructions apply to code reviews on pull requests in this repository.

## Project Context

This is PayPlug's official Sylius plugin. PayPlug is a Payment Service Provider (PSP). The plugin integrates PayPlug's payment processing into Sylius via multiple payment methods: standard card (PayPlug), Oney financing, Bancontact, Scalapay, Apple Pay, and American Express.

**Stack**: PHP 8.2+, Sylius 2.0+, Symfony 6.4+, Payum, `payplug/payplug-php ^4.0`

## Intentional Patterns — Do Not Flag as Issues

- **`sleep(10)` in `NotifyAction`** — intentional, prevents a race condition between the IPN webhook and the user redirect. Never suggest removing it.
- **No direct SDK calls** — `PayPlugApiClient` is the only allowed entry point to the PayPlug PHP SDK. Any direct call to SDK classes bypasses this and is a bug.
- **Dual architecture** — Sylius 2.1+ uses a command/response provider model alongside legacy Payum actions. Both coexist intentionally.
- **Card saving condition** — a card is only saved when the PayPlug API response includes `metadata['customer_id']`. Absence of this guard is a bug.
- **`payment_context.cart`** — required in the PayPlug API payload for Oney and Scalapay payments. Missing it causes API rejection.
- **Distributed lock in `NotifyAction`** — Symfony Lock is used on payment ID to prevent concurrent webhook processing. Do not flag as over-engineering.

## Code Review Dimensions

### Security
- SQL injection, XSS, CSRF
- Authentication and authorization flaws
- Secrets or credentials committed in code
- Insecure deserialization, path traversal, SSRF
- Direct calls to PayPlug PHP SDK classes instead of going through `PayPlugApiClient`

### Performance
- N+1 queries (especially in Sylius entity traversal)
- Unnecessary memory allocations
- Algorithmic complexity (O(n²) in hot paths)
- Missing database indexes
- Unbounded queries or loops
- Resource leaks

### Correctness
- Edge cases: empty input, null, overflow
- Race conditions and concurrency issues
- Error handling and propagation
- Off-by-one errors, type safety
- `declare(strict_types=1)` must be present in every PHP file
- For new payment gateways (PPRO pattern): verify the full implementation checklist is covered (gateway factory, form type, resolver decorator, refund provider, templates, service definitions, translations)

### Maintainability
- Naming clarity, single responsibility, duplication
- Test coverage: PHPUnit in `tests/PHPUnit/`, Behat in `features/`
- Documentation for non-obvious logic only — do not flag missing comments on self-explanatory code
- PHPStan level max compliance; suppressions must go in `ruleset/phpstan-baseline.neon`
- ECS coding standard based on `sylius-labs/coding-standard`

## Output Format

Rate each dimension: **Good** / **Needs Attention** / **Critical**

List findings with file path and line number. Lead with Critical findings. Include positive observations alongside issues.
