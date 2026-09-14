<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PayPlugApiClientFactory implements PayPlugApiClientFactoryInterface
{
    /** @var ContainerInterface */
    private $container;

    /** @var string */
    private $serviceName;

    public function __construct(ContainerInterface $container, string $serviceName)
    {
        $this->container = $container;
        $this->serviceName = $serviceName;
    }

    /**
     * The Behat suites stub one PayPlug account for the whole scenario, so the payment method is
     * ignored here — the mocked client is the same whichever one is passed.
     */
    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): PayPlugApiClientInterface
    {
        return new PayPlugApiClient($this->container, $this->serviceName);
    }
}
