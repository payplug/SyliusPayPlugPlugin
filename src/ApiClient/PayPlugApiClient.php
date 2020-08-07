<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Authentication;
use Payplug\Payplug;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use Webmozart\Assert\Assert;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    /** @var \Payplug\Payplug */
    private $configuration;

    public function __construct(string $secretKey)
    {
        $this->configuration = \Payplug\Payplug::init(['secretKey' => $secretKey]);
    }

    /**
     * @deprecated use DI instead to get a pre-configured client
     */
    public function initialise(string $secretKey): void
    {
        \Payplug\Payplug::setSecretKey($secretKey);
    }

    public function getAccount(): array
    {
        return Authentication::getAccount($this->configuration)['httpResponse'] ?? [];
    }

    public function getPermissions(): array
    {
        return \Payplug\Authentication::getPermissions($this->configuration) ?? [];
    }

    public function getConfiguration(): Payplug
    {
        return $this->configuration;
    }

    public function createPayment(array $data): Payment
    {
        $payment = \Payplug\Payment::create($data, $this->configuration);
        Assert::isInstanceOf($payment, Payment::class);

        return $payment;
    }

    public function refundPayment(string $paymentId): Refund
    {
        /** @var Refund|null $refund */
        $refund = \Payplug\Refund::create($paymentId, null, $this->configuration);
        Assert::isInstanceOf($refund, Refund::class);

        return $refund;
    }

    public function refundPaymentWithAmount(string $paymentId, int $amount, int $refundId): Refund
    {
        /** @var Refund|null $refund */
        $refund = \Payplug\Refund::create($paymentId, [
            'amount' => $amount,
            'metadata' => ['refund_from_sylius' => true],
        ], $this->configuration);
        Assert::isInstanceOf($refund, Refund::class);

        return $refund;
    }

    public function treat(string $input): IVerifiableAPIResource
    {
        return \Payplug\Notification::treat($input, $this->configuration);
    }

    public function retrieve(string $paymentId): Payment
    {
        $payment = \Payplug\Payment::retrieve($paymentId, $this->configuration);
        Assert::isInstanceOf($payment, Payment::class);

        return $payment;
    }
}
