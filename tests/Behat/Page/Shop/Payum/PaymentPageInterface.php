<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Shop\Payum;

use FriendsOfBehat\PageObjectExtension\Page\PageInterface;

interface PaymentPageInterface extends PageInterface
{
    public function capture(array $parameters = []): void;

    public function notify(array $postData): void;
}
