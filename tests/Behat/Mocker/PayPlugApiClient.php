<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    /** @var PayPlugApiClientInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function initialise(string $secretKey, ?string $notificationUrlDev = null): void
    {
        $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->initialise($secretKey, $notificationUrlDev);
    }

    public function createPayment(array $data): Payment
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->createPayment($data);
    }

    public function refundPayment(string $paymentId): Refund
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->refundPayment($paymentId);
    }

    public function treat($input)
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->treat($input);
    }

    public function retrieve(string $paymentId): Payment
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->retrieve($paymentId);
    }

    public function getNotificationUrlDev(): ?string
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->getNotificationUrlDev();
    }
}
