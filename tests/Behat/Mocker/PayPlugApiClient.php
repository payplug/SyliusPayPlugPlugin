<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    /** @var PayPlugApiClientInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function initialise(string $secretKey): void
    {
        $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->initialise($secretKey);
    }

    public function createPayment(array $data): Payment
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->createPayment($data);
    }

    public function refundPayment(string $paymentId): Refund
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->refundPayment($paymentId);
    }

    public function treat(string $input): IVerifiableAPIResource
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->treat($input);
    }

    public function retrieve(string $paymentId): Payment
    {
        return $this->container->get('payplug_sylius_payplug_plugin.api_client.payplug')->retrieve($paymentId);
    }
}
