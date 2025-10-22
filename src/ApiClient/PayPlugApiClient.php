<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Authentication;
use Payplug\Card;
use Payplug\Core\HttpClient;
use Payplug\Exception\UnauthorizedException;
use Payplug\Notification;
use Payplug\Payplug;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PayPlugSyliusPayPlugPlugin;
use Sylius\Bundle\CoreBundle\SyliusCoreBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Webmozart\Assert\Assert;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    private const CURRENT_API_VERSION = '2019-08-06';

    /** @var Payplug */
    private $configuration;

    /** @var string */
    private $factoryName;

    /** @var CacheInterface */
    private $cache;

    public function __construct(string $secretKey, ?string $factoryName = null, ?CacheInterface $cache = null)
    {
        $this->configuration = Payplug::init([
            'secretKey' => $secretKey,
            'apiVersion' => self::CURRENT_API_VERSION,
        ]);
        $this->factoryName = $factoryName ?? PayPlugGatewayFactory::FACTORY_NAME;

        if (!$cache instanceof \Symfony\Contracts\Cache\CacheInterface) {
            $cache = new ArrayAdapter();
        }
        $this->cache = $cache;

        HttpClient::addDefaultUserAgentProduct(
            'PayPlug-Sylius',
            PayPlugSyliusPayPlugPlugin::VERSION,
            'Sylius/' . SyliusCoreBundle::VERSION,
        );
    }

    public function getAccount(bool $refresh = false): array
    {
        $cacheKey = 'payplug_account_' . substr($this->configuration->getToken(), 8);
        if ($refresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, fn (): array => Authentication::getAccount($this->configuration)['httpResponse'] ?? []);
    }

    public function getGatewayFactoryName(): string
    {
        return $this->factoryName;
    }

    public function getPermissions(): array
    {
        try {
            return Authentication::getPermissions($this->configuration) ?? [];
        } catch (UnauthorizedException) {
            return [];
        }
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

    public function abortPayment(string $paymentId): Payment
    {
        $payment = \Payplug\Payment::abort($paymentId, $this->configuration);
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
        return Notification::treat($input, $this->configuration);
    }

    public function retrieve(string $paymentId): Payment
    {
        $payment = \Payplug\Payment::retrieve($paymentId, $this->configuration);
        Assert::isInstanceOf($payment, Payment::class);

        return $payment;
    }

    public function deleteCard(string $card): void
    {
        Card::delete($card, $this->configuration);
    }
}
