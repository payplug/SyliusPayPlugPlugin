@paying_with_payplug_for_order
Feature: Paying with Hosted Fields during checkout
    In order to buy products
    As a Customer
    I want to see Hosted Fields as a distinct payment method at checkout

    Background:
        Given the store operates on a single channel in "United States"
        And that channel also allows to shop using the "EUR" currency
        And there is a user "john@bitbag.pl" identified by "password123"
        And I changed my currency to "EUR"
        And the store has a payment method "PayPlug Hosted Fields" with a code "payplug_hosted_fields" and PayPlug Hosted Fields payment gateway
        And the store ships everywhere for free
        And the store has "DHL" shipping method with "$0.00" fee
        And I am logged in as "john@bitbag.pl"

    @ui
    Scenario: I can see and select the Hosted Fields payment method
        Given the store has a product "PHP T-Shirt" priced at "€50.00"
        And I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "PayPlug Hosted Fields" payment method
