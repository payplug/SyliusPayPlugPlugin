@refunding_payplug_payment
Feature: Refunding order's PayPlug payment
    In order to refund order's payment with PayPlug
    As an Administrator
    I want to be able to select PayPlug payment for refund only if PayPlug was the first payment gateway

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Green Arrow" priced at "$10.00"
        And the store ships everywhere for free
        And the store has a payment method "PayPlug" with a code "payplug" and PayPlug payment gateway
        And the store has a payment method "Cash on delivery" with a code "cash_on_delivery" other than PayPlug payment gateway
        And there is a customer "oliver@teamarrow.com" that placed an order "#00000001"
        And the customer bought a single "Green Arrow"
        And the customer chose "Free" shipping method to "United States" with "Cash on delivery" payment
        And this order is already paid
        And I am logged in as an administrator
        And I am viewing the summary of this order

    @ui
    Scenario: Should be able to refund using payplug payment
        When I want to refund some units of order "00000001"
        Then there should be "Cash on delivery" payment method
        Then there should not be "PayPlug" payment method
        Then there should not be "Oney" payment method
