<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use Payplug\Payplug;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PayPlugApiClient implements PayPlugApiClientInterface
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

    public function initialise(string $secretKey): void
    {
        $this->container->get($this->serviceName)->initialise($secretKey);
    }

    public function getPermissions(): array
    {
        return $this->container->get($this->serviceName)->getPermissions();
    }

    public function createPayment(array $data): Payment
    {
        return $this->container->get($this->serviceName)->createPayment($data);
    }

    public function abortPayment(string $paymentId): Payment
    {
        return $this->container->get($this->serviceName)->abortPayment($paymentId);
    }

    public function refundPayment(string $paymentId): Refund
    {
        return $this->container->get($this->serviceName)->refundPayment($paymentId);
    }

    public function treat(string $input): IVerifiableAPIResource
    {
        return $this->container->get($this->serviceName)->treat($input);
    }

    public function retrieve(string $paymentId): Payment
    {
        return $this->container->get($this->serviceName)->retrieve($paymentId);
    }

    public function refundPaymentWithAmount(string $paymentId, int $amount, int $refundId): Refund
    {
        return $this->container->get($this->serviceName)->refundPaymentWithAmount($paymentId, 100, $refundId);
    }

    public function getConfiguration(): Payplug
    {
        return $this->container->get($this->serviceName)->getConfiguration();
    }

    public function getAccount(bool $refresh = false): array
    {
        return $this->container->get($this->serviceName)->getAccount();
    }

    public function getGatewayFactoryName(): string
    {
        return $this->container->get($this->serviceName)->getGatewayFactoryName();
    }

    public function deleteCard(string $card): void
    {
        return;
    }
}
