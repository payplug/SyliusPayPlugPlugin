<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
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
     * @Then I should be notified that :fields fields cannot be blank
     */
    public function iShouldBeNotifiedThatCannotBeBlank(string $fields): void
    {
        $fields = explode(',', $fields);

        foreach ($fields as $field) {
            Assert::true($this->createPage->containsErrorWithMessage(sprintf(
                '%s cannot be blank.',
                trim($field)
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
}
