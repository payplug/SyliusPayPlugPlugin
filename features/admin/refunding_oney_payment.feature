@refunding_payplug_payment
Feature: Refunding order's Oney payment
    In order to refund order's payment
    As an Administrator
    I want to be able to mark order's payment as refunded

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Green Arrow" priced at "$10.00"
        And the store has a product "Red Arrow" priced at "$330.00"
        And the store ships everywhere for free
        And the store has a payment method "Oney" with a code "payplug_oney" and Oney payment gateway
        And the store has a payment method "Cash on delivery" with a code "cash_on_delivery" other than PayPlug payment gateway
        And there is a customer "oliver@teamarrow.com" that placed an order "#00000001"
        And the customer bought a single "Green Arrow"
        And the customer bought a single "Red Arrow"
        And the customer chose "Free" shipping method to "United States" with "Oney" payment
        And this order with payplug api payment is already paid
        And I am logged in as an administrator
        And I am viewing the summary of this order

    @ui
    Scenario: Should be able to refund using oney payment as soon as the API allows it
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment
        Then I should see a success message "Selected order units have been successfully refunded"
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Should not be able to refund using oney payment before the date given by the API
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment before the API refund window opens on "2100-01-01 12:00:00 UTC"
        Then I should see an error message "The Oney refund will only be possible from Jan 1, 2100"
        Then this order refunded total should be "$0.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Should not be able to refund using oney payment once the API refund period has ended
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment after the API refund window closed on "2020-01-01 12:00:00 UTC"
        Then I should see an error message "The Oney refund period ended on Jan 1, 2020"
        Then this order refunded total should be "$0.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Total refund order from PayPlug portal
        When I refund totally this order's from payplug portal
        And I want to refund some units of order "00000001"
        Then this order refunded total should be "$340.00"

    @ui
    Scenario: Partial refund of one product from PayPlug portal
        When I refund partially this order's from payplug portal with 3.00
        And I want to refund some units of order "00000001"
        Then 1st "Green Arrow" product should have "$3.00" refunded
        And this order refunded total should be "$3.00"

    @ui
    Scenario: Two Partial refund of one product from PayPlug portal
        When I refund partially this order's from payplug portal with 3.00
        When I refund partially this order's from payplug portal with 7.00
        And I want to refund some units of order "00000001"
        Then 1st "Green Arrow" product should have "$10.00" refunded
        And this order refunded total should be "$10.00"

    @ui
    Scenario: Two Partial refund from PayPlug portal
        When I refund partially this order's from payplug portal with 13.00
        When I refund partially this order's from payplug portal with 17.00
        And I want to refund some units of order "00000001"
        Then 1st "Green Arrow" product should have "$10.00" refunded
        Then 1st "Red Arrow" product should have "$20.00" refunded
        And this order refunded total should be "$30.00"

    @ui
    Scenario: Two Partial refund with total amount from PayPlug portal
        When I refund partially this order's from payplug portal with 10.00
        When I refund partially this order's from payplug portal with 330.00
        And I want to refund some units of order "00000001"
        Then 1st "Green Arrow" product should have "$10.00" refunded
        Then 1st "Red Arrow" product should have "$330.00" refunded
        And this order refunded total should be "$340.00"

    @ui
    Scenario: Should be able to refund another item right after a previous oney refund
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment
        Then I should see a success message "Selected order units have been successfully refunded"
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products
        When For this order I decide to refund 1st "Red Arrow" product with "Oney" payment
        Then I should see a success message "Selected order units have been successfully refunded"
        And this order refunded total should be "$340.00"
