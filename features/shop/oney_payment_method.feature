@paying_with_payplug_for_order
Feature: Paying with Oney during checkout
    In order to buy products
    As a Customer
    I want to be able to pay with Oney if the total is between 100 and 3000 euros.

    Background:
        Given the store operates on a channel named "Web-FR"
        And that channel allows to shop using the "French (France)" locale
        And it uses the "French (France)" locale by default
        And that channel also allows to shop using the "EUR" currency
        And there is a user "john@payplug.fr" identified by "password123"
        And the store has customer "synolia@payplug.com" with name "Synolia Payplug" and phone number "0606060606" since "2011-01-10 21:00"
        And I changed my currency to "EUR"
        And the store has a payment method "PayPlug" with a code "payplug" and PayPlug payment gateway
        And the store has a payment method "Oney" with a code "oney" and Oney payment gateway
        And the store ships everywhere for free
        And the store has a zone "France" with code "FR"
        And the store has "DHL" shipping method with "$0.00" fee
        And I am logged in as "john@payplug.fr"
        And Oney is enabled

    @ui
    Scenario: I can use Oney with cart of 100 euros
        Given the store has a product "PHP T-Shirt" priced at "€100.00" in "Web-FR" channel
        And I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "Oney" payment method

    @ui
    Scenario: I can use Oney with cart of 3000 euros
        Given the store has a product "PHP T-Shirt" priced at "€3000"
        And I added product "PHP T-Shirt" to the cart
        And I am at the checkout addressing step
        And I specify the shipping address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I specify the billing address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney with cart of less than 100 euros
        Given the store has a product "PHP T-Shirt" priced at "€99.99"
        And I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney with cart of more than 3000 euros
        Given the store has a product "PHP T-Shirt" priced at "€3000.01"
        And I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method

    @ui
    Scenario: I can use Oney with cart of less than 999 items
        Given the store has a product "PHP T-Shirt" priced at "€1.00"
        And I add 998 of them to my cart
        And I am at the checkout addressing step
        And I specify the shipping address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I specify the billing address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "Oney" payment method

    @ui
    Scenario: I can use Oney with cart 999 items
        Given the store has a product "PHP T-Shirt" priced at "€1.00"
        And I add 999 of them to my cart
        And I am at the checkout addressing step
        And I specify the shipping address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I specify the billing address as "Bordeaux", "23 Quai de la gare", "33000", "France" for "Synolia Payplug"
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney with cart of more than 999 items
        Given the store has a product "PHP T-Shirt" priced at "€1.00"
        And I add 1000 of them to my cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney with cart of 999 items and more than "€3000.00"
        Given the store has a product "PHP T-Shirt" priced at "€4000.00"
        And I add 999 of them to my cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney with cart of 999 items and less than "€100.00"
        Given the store has a product "PHP T-Shirt" priced at "€90.00"
        And I add 999 of them to my cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method

    @ui
    Scenario: I cannot use Oney as payment method when it's disabled in my account
        Given Oney is disabled
        Given the store has a product "PHP T-Shirt" priced at "€100"
        And I added product "PHP T-Shirt" to the cart
        And I chose "DHL" shipping method
        Then I should be on the checkout payment step
        And I should not be able to select "Oney" payment method
