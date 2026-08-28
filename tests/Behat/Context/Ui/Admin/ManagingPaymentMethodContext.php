<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use PHPUnit\Framework\MockObject\Generator;
use Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Admin\PaymentMethod\CreatePageInterface;
use Webmozart\Assert\Assert;

final class ManagingPaymentMethodContext implements Context
{
    /** @var CreatePageInterface */
    private $createPage;

    public function __construct(CreatePageInterface $createPage)
    {
        $this->createPage = $createPage;
    }

    /**
     * @Given I want to create a new PayPlug payment method
     */
    public function iWantToCreateANewPayPlugPaymentMethod(): void
    {
        $this->createPage->open(['factory' => 'payplug']);
    }

    /**
     * @Then I should be notified that The :fields cannot be empty.
     */
    public function iShouldBeNotifiedThatCannotBeBlank(string $fields): void
    {
        $fields = explode(',', $fields);

        foreach ($fields as $field) {
            Assert::true($this->createPage->containsErrorWithMessage(sprintf(
                'The %s cannot be empty.',
                strtolower(trim($field)),
            )));
        }
    }

    /**
     * @Then I should be notified that :message
     */
    public function iShouldBeNotifiedThat(string $message): void
    {
        Assert::true($this->createPage->containsErrorWithMessage($message));
    }

    /**
     * @When I fill the Secret key with :secretKey
     */
    public function iFillTheSecretKeyWith(string $secretKey): void
    {
        $this->createPage->setSecretKey($secretKey);
    }

    /**
     * @When This secret Key is valid
     */
    public function thisSecretKeyIsValid(): void
    {
        // Inspired by https://github.com/payplug/payplug-php/blob/master/tests/unit_tests/AuthenticationTest.php
        $mockGenerator = new Generator();
        $requestMock = $mockGenerator->getMock(\Payplug\Core\IHttpRequest::class);
        $response = [
            'is_live' => true,
            'object' => 'account',
            'id' => '12345',
            'configuration' => [
                'currencies' => [],
                'min_amounts' => [],
                'max_amounts' => [],
            ],
            'permissions' => [
                'use_live_mode' => true,
                'can_save_cards' => false,
            ],
        ];
        $requestMock
            ->method('exec')
            ->willReturn(json_encode($response));
        $requestMock
            ->method('getInfo')
            ->willReturn(200);

        \Payplug\Core\HttpClient::$REQUEST_HANDLER = $requestMock;
    }
}
