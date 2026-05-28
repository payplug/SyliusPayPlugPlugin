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
- Webhook payloads must be verified via `PayPlugApiClient::treat()` before any processing — never act on raw `php://input` directly
- Card data (PAN, CVV, raw card numbers) must never appear in logs, error messages, or stored fields
- API secret keys must never appear in logs, exception messages, or HTTP responses
- Payment amounts must be validated server-side — never trust a client-submitted amount
- `redirect_url` values must come from the PayPlug API response, never constructed from user input (open redirect risk)

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
- Amount unit: Payum works in the smallest currency unit (cents). Any conversion must be explicit — silently mixing units is a payment amount bug
- EUR-only enforcement must happen before the API call, not after
- `factory_name` must be stored alongside `redirect_url` in payment details — missing it allows a stale redirect from one gateway to be reused when retrying with a different one
- Refund amounts must not exceed the remaining refundable amount on the payment
- State machine transition names must match Sylius/Payum constants exactly; Oney adds a custom `oney_request_payment` transition that is easy to misspell or omit

### Maintainability
- Naming clarity, single responsibility, duplication
- Test coverage: PHPUnit in `tests/PHPUnit/`, Behat in `features/`
- Documentation for non-obvious logic only — do not flag missing comments on self-explanatory code
- PHPStan level max compliance; suppressions must go in `ruleset/phpstan-baseline.neon`
- ECS coding standard based on `sylius-labs/coding-standard`
- Translations must be complete in all three locales (`en`, `fr`, `it`) — partial translation is a regression
- New services must be registered in both `config/services.yaml` (command/response providers) and the relevant `config/services/*.xml` file (Payum factory or API client) — registering only one side causes runtime failures

### Headless Compliance

In a headless Sylius setup the frontend is a decoupled SPA or mobile app — it cannot follow server-side HTTP redirects. Any shop-facing response that performs a redirect instead of returning JSON breaks the headless flow.

- Shop-facing controllers and response providers must return a `JsonResponse` containing a `redirect_url` field rather than a `RedirectResponse`. The client is responsible for performing the redirect.
- Known existing offenders (do not flag these as new issues, but flag any new code that replicates the same pattern):
  - `src/OrderPay/Provider/CaptureHttpResponseProvider.php` — returns `RedirectResponse($data['redirect_url'])`
  - `src/Controller/OneClickAction.php` — returns `RedirectResponse` on all exit paths
- Admin-facing controllers (`src/Action/Admin/`) are exempt — headless compliance only applies to the shop payment flow.
- If a new controller or response provider in the shop flow returns `RedirectResponse`, flag it as a headless compliance issue.

## Output Format

Structure the review comment exactly as follows:

### 1. What's Good

A bullet list of positive observations — things done well, non-obvious correct decisions, solid patterns.

---

### 2. Summary table

A markdown table with two columns: **Dimension** and **Rating**. One row per review dimension. Use emoji inline with the rating text:

| Dimension | Rating |
|---|---|
| Security | ✅ Fine |
| Correctness | ⚠️ Medium (short reason) |
| Performance | ✅ Fine |
| Maintainability | ⚠️ Low (short reason) |

Severity scale:
- ✅ **Fine** — no issues
- ⚠️ **Low / Medium** — should be fixed but not blocking
- ❌ **High / Critical** — must be fixed before merge

---

### 3. Closing one-liner

A single sentence summarising what needs to be addressed before merge (or that the PR is ready if nothing critical).

---

### 4. Individual findings (one section per issue)

Each finding follows this exact structure:

**Heading:** `[Dimension] [emoji] [Severity]` — e.g. `Security ⚠️ Medium`

**Subtitle (bold):** short title followed by the file path and line number as a markdown link — e.g. `**Path traversal in getPayment** (PaymentClient.php:290)`

**Code block:** the relevant snippet from the diff showing the problem.

**Explanation paragraph:** what the risk is and why it matters. Be concrete.

**Fix line:** start with `Fix:` in bold, then a brief description, followed by a code block showing the suggested fix.

Lead with Critical/High findings. Omit the findings section entirely if there are no issues.

## Iterative Reviews

When reviewing a new commit on a PR that already has open review threads:

- **Resolve threads** for issues that have been addressed in the new commit — do not leave them open if the fix is present.
- **Do not re-open or re-comment** on issues that were already resolved in a previous round.
- Only open new threads for issues that are genuinely new or that remain unresolved.
- If a previous finding was partially addressed, update the thread with what still needs attention rather than opening a duplicate.
