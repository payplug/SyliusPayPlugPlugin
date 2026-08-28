@update_payment_state @cli
Feature: Updating payment state of orders
    In order to update payment state of orders
    As a Developer
    I want to execute command to update orders status

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a payment method "PayPlug" with a code "payplug" and PayPlug payment gateway
        And the store has a product "Sylius T-Shirt"
        And this product has "Red XL" variant priced at "$40"

    Scenario: No updating payment state when no orders
        When I run update payment state command
        Then I should see "Updated: 0" in output

    Scenario: One payment state need to be updating
        When a single customer has placed an order for total of "1111"
        And a payplug payment is created
        And I run update payment state command
        Then I should see "Updated: 1" in output
