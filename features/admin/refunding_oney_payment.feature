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
    Scenario: Should not be able to refund using oney payment before 48 hours
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        Then there should be "Cash on delivery" payment method
        Then I should still be able to refund order shipment with "Oney" payment
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment
        Then I should see an error message "Another transaction have been made less than 48 hours ago."
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Should be able to refund using oney payment after 48 hours
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        Then there should be "Cash on delivery" payment method
        Then I should still be able to refund order shipment with "Oney" payment
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment after 48 hours
        Then I should see a success message "Selected order units have been successfully refunded"
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products

    @ui
    Scenario: Should not be able to refund another item before last transaction exceed 48 hours
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        Then there should be "Cash on delivery" payment method
        Then I should still be able to refund order shipment with "Oney" payment
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment after 48 hours
        Then I should see a success message "Selected order units have been successfully refunded"
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products
        Then I should still be able to refund order shipment with "Oney" payment
        When For this order I decide to refund 1st "Red Arrow" product with "Oney" payment
        Then this order refunded total should be "$340.00"
        Then I should see an error message "Another transaction have been made less than 48 hours ago."

    @ui
    Scenario: Should not be able to refund another item before last transaction exceed 48 hours
        When I want to refund some units of order "00000001"
        Then there should be "Oney" payment method
        Then there should be "Cash on delivery" payment method
        Then I should still be able to refund order shipment with "Oney" payment
        When For this order I decide to refund 1st "Green Arrow" product with "Oney" payment after 48 hours
        Then I should see a success message "Selected order units have been successfully refunded"
        Then this order refunded total should be "$10.00"
        And I should be able to refund 1 "Red Arrow" products
        Then I should still be able to refund order shipment with "Oney" payment
        Then I wait 48 hours after the last refund of this order
        When For this order I decide to refund 1st "Red Arrow" product with "Oney" payment
        Then this order refunded total should be "$340.00"
        Then I should see a success message "Selected order units have been successfully refunded"

