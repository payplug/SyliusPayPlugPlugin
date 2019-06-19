<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Shop\Payum;

use FriendsOfBehat\PageObjectExtension\Page\PageInterface;

interface PaymentPageInterface extends PageInterface
{
    public function capture(array $parameters = []): void;

    public function notify(array $postData): void;
}
