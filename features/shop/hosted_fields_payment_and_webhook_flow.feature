# PRE-3551 / Task 14 — end-to-end coverage for the payplug_uhf (Unified Hosted Fields) capture +
# UPC webhook pipeline built by Tasks 1-13.
#
# IMPORTANT — read before relying on this file:
#
# This file is WRITTEN BUT NOT EXECUTABLE YET. Every step in the Background and in the opening
# "Given"/"When"/"Then" lines that also appear in features/shop/hosted_fields_payment_method.feature
# or features/shop/update_payment_state.feature is backed by a real, already-merged step
# definition and is safe to trust. Steps marked "# NEW — not yet defined" below do not resolve to
# any step definition in this codebase today; Behat would report them as "undefined", not pass or
# fail. See tests-Behat/task-14-report.md (PRE-3551 SDD folder) for why, and for a sketch of the
# infrastructure (Mocker + a raw-HTTP webhook page object + a PaymentRequest fixture step) that
# would need to be designed and built — not guessed — before these two scenarios can actually run.
@paying_with_payplug_for_order
Feature: Paying with Unified Hosted Fields and receiving the UPC webhook confirmation
    In order to buy products with a card processed through PayPlug's Unified API
    As a Customer
    I want my order to be confirmed once the asynchronous UPC webhook notification arrives

    Background:
        Given the store operates on a single channel in "United States"
        And that channel also allows to shop using the "EUR" currency
        And there is a user "john@bitbag.pl" identified by "password123"
        And I changed my currency to "EUR"
        And the store has a payment method "Unified Hosted Fields" with a code "payplug_uhf" and PayPlug Hosted Fields payment gateway
        And This secret Key is valid
        And the store ships everywhere for free
        And the store has "DHL" shipping method with "$0.00" fee
        And I am logged in as "john@bitbag.pl"
        And the store has a product "PHP T-Shirt" priced at "€50.00"

    @ui
    Scenario: Paying with Unified Hosted Fields completes the order without a 3DS redirect
        Given I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "Unified Hosted Fields" payment method
        And I select "Unified Hosted Fields" payment method
        And I should see the "#card-container" element on the page
        # NEW — not yet defined. Needs: (1) HostedPaymentCreatorInterface made a public service
        # (currently private by DI default) so a Behat Mocker can swap it, mirroring
        # tests/Behat/Mocker/PayPlugApiMocker's pattern for the legacy PayPlugApiClientInterface;
        # (2) a new UhfApiMocker::mockCreateHostedPaymentWithoutRedirect() stubbing
        # createHostedPayment() to return a HostedPaymentOutput with redirectUrl = null.
        And the Unified API is stubbed to accept the payment without requiring 3DS
        # NEW — not yet defined, and not just missing a step: card entry goes through a real,
        # cross-origin Dalenys hostedFields iframe SDK (assets/shop/controllers/hosted-fields_controller.js)
        # loaded from PayPlug's own domain. There is no server-side seam to stub this — Behat would
        # need either a live sandbox tokenization endpoint or a test-only shim replacing
        # window.dalenys before the page loads. Deciding whether to build that shim is a product/
        # architecture call, not a step-definition gap.
        When I fill in my card details and confirm the payment
        Then I should see the thank you page
        # NEW — not yet defined. "Eventually" implies polling/waiting for an async webhook POST
        # that nothing in this suite currently sends (see Scenario 2's blockers below).
        And the order's payment should eventually be marked as paid once the webhook is delivered

    @ui
    Scenario: A retried UPC webhook notification is not processed twice
        # NEW — not yet defined. Needs a fixture step that creates an Order + Payment already
        # past the capture step (hosted_fields_created_at set, Payment state "processing") plus a
        # real Sylius PaymentRequestInterface (action=notify, hash known) so the scenario has a
        # real notify URL to POST to — sylius/sylius's PaymentRequest entity/repository isn't
        # available in this checkout's vendor/ to confirm the exact construction API, so writing
        # this without running it would be guessing at Sylius core internals, not this plugin's.
        Given an order paid with "Unified Hosted Fields" is awaiting webhook confirmation
        # NEW — not yet defined. Needs a Page object that performs a raw HTTP POST (via the Mink
        # BrowserKit driver's underlying client) to the sylius_payment_request_notify route for
        # that PaymentRequest's hash, with body {"id","execCode","orderId","amount"} and an
        # Authorization header matching whatever the "payplug_webhook_authorization_header" gateway
        # config key was seeded to in the Given step above (see
        # src/Upc/SyliusUpcConfigurationRepository::get() and
        # src/Command/Handler/NotifyHostedPaymentRequestHandler — confirmed by
        # tests/PHPUnit/Command/Handler/NotifyHostedPaymentRequestHandlerTest, which is the most
        # reliable reference for the exact payload/header shape).
        When the UPC webhook notification is delivered
        And the same UPC webhook notification is delivered again
        # NEW — not yet defined. No existing step asserts Payment state (only order state, via
        # CheckoutContext::theLatestOrderHasState) or asserts a call count on IPaymentRepository.
        Then the order's payment should be marked as paid exactly once
