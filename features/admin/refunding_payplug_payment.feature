@refunding_payplug_payment
Feature: Refunding order's PayPlug payment
    In order to refund order's payment
    As an Administrator
    I want to be able to mark order's payment as refunded

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Green Arrow" priced at "$10.00"
        And the store has a product "Red Arrow" priced at "$330.00"
        And the store ships everywhere for free
        And the store has a payment method "PayPlug" with a code "payplug" and PayPlug payment gateway
        And there is a customer "oliver@teamarrow.com" that placed an order "#00000001"
        And the customer bought a single "Green Arrow"
        And the customer bought a single "Red Arrow"
        And the customer chose "Free" shipping method to "United States" with "PayPlug" payment
        And this order with payplug payment is already paid
        And I am logged in as an administrator
        And I am viewing the summary of this order

    @ui
    Scenario: Marking order's payment as refunded
        When I mark this order's payplug payment as refunded
        Then I should be notified that the order's payment has been successfully refunded
        And it should have payment with state refunded

    @ui
    Scenario: Marking an order as refunded after refunding all its payments
        When I mark this order's payplug payment as refunded
        Then it should have payment with state refunded
        And it's payment state should be refunded

    @ui
    Scenario: Should be able to refund using payplug payment
        When I want to refund some units of order "00000001"
        Then I should still be able to refund order shipment with "PayPlug" payment
        When For this order I decide to refund 1st "Green Arrow" product with "PayPlug" payment
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Should be able to refund using payplug payment
        When I want to refund some units of order "00000001"
        Then I should still be able to refund order shipment with "PayPlug" payment
        When For this order I decide to refund 1st "Green Arrow" product with "PayPlug" payment
        Then this order refunded total should be "$10.00"
        Then I should still be able to refund order shipment with "PayPlug" payment
        When For this order I decide to refund 1st "Red Arrow" product with "PayPlug" payment
        Then this order refunded total should be "$340.00"
        And I should not be able to refund anything
