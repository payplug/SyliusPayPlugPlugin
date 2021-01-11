@managing_payplug_payment_method
Feature: Adding a new payplug payment method
    In order to pay for orders in different ways
    As an Administrator
    I want to add a new payment method to the registry

    Background:
        Given the store operates on a channel named "Web-USD" in "USD" currency
        And I am logged in as an administrator

    @ui
    Scenario: Adding a new payplug payment method
        Given I want to create a new PayPlug payment method
        When I name it "PayPlug" in "English (United States)"
        And I specify its code as "payplug_test"
        And I fill the Secret key with "test"
        And This secret Key is valid
        And make it available in channel "Web-USD"
        And I add it
        Then I should be notified that it has been successfully created
        And the payment method "PayPlug" should appear in the registry
