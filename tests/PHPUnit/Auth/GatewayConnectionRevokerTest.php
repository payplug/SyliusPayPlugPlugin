<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Auth\GatewayConnectionRevoker;
use PayPlug\SyliusPayPlugPlugin\Auth\SyliusTokenCache;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The token cache is exercised through a real SyliusTokenCache over an ArrayAdapter rather than a
 * mocked ITokenCache: the key the revoker has to delete is the one TokenManager writes, sanitized
 * by SyliusTokenCache on the way in, and a mock asserting on a literal key would pass even if that
 * sanitization changed underneath it.
 */
final class GatewayConnectionRevokerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private SyliusTokenCache $tokenCache;

    private GatewayConnectionRevoker $revoker;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tokenCache = new SyliusTokenCache(new ArrayAdapter());
        $this->revoker = new GatewayConnectionRevoker($this->entityManager, $this->tokenCache);
    }

    public function testRevoke_removesTheOAuthCredentialsFromTheGatewayConfig(): void
    {
        $paymentMethod = $this->paymentMethod();

        $this->revoker->revoke($paymentMethod);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        self::assertArrayNotHasKey('live_client', $config);
        self::assertArrayNotHasKey('test_client', $config);
        self::assertArrayNotHasKey('account_email', $config);
    }

    public function testRevoke_keepsTheMerchantSettingsThatAreNotCredentials(): void
    {
        $paymentMethod = $this->paymentMethod(config: [
            'live' => true,
            PayPlugGatewayFactory::ONE_CLICK => true,
            'fees_for' => 'merchant',
        ]);

        $this->revoker->revoke($paymentMethod);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        self::assertTrue($config['live']);
        self::assertTrue($config[PayPlugGatewayFactory::ONE_CLICK]);
        self::assertSame('merchant', $config['fees_for']);
    }

    public function testRevoke_disablesThePaymentMethod(): void
    {
        $paymentMethod = $this->paymentMethod();
        $paymentMethod->enable();

        $this->revoker->revoke($paymentMethod);

        self::assertFalse($paymentMethod->isEnabled());
    }

    public function testRevoke_dropsTheCachedTokenOfBothEnvironments(): void
    {
        $this->tokenCache->set('upc_oauth_token:client_live', 'live-access-token', 3600);
        $this->tokenCache->set('upc_oauth_token:client_test', 'test-access-token', 3600);

        $this->revoker->revoke($this->paymentMethod());

        self::assertNull($this->tokenCache->get('upc_oauth_token:client_live'));
        self::assertNull($this->tokenCache->get('upc_oauth_token:client_test'));
    }

    public function testRevoke_leavesAnotherGatewaysCachedTokenAlone(): void
    {
        $this->tokenCache->set('upc_oauth_token:client_of_another_channel', 'other-access-token', 3600);

        $this->revoker->revoke($this->paymentMethod());

        self::assertSame('other-access-token', $this->tokenCache->get('upc_oauth_token:client_of_another_channel'));
    }

    public function testRevoke_hostedFieldsCbGateway_alsoClearsTheHostedFieldsAccountId(): void
    {
        $paymentMethod = $this->paymentMethod(config: [
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acc_123',
        ]);

        $this->revoker->revoke($paymentMethod);

        self::assertArrayNotHasKey(PayPlugGatewayFactory::HF_IDENTIFIER, $paymentMethod->getGatewayConfig()?->getConfig() ?? []);
    }

    public function testRevoke_cbGatewayWithoutHostedFields_keepsTheHostedFieldsAccountId(): void
    {
        $paymentMethod = $this->paymentMethod(config: [
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acc_123',
        ]);

        $this->revoker->revoke($paymentMethod);

        self::assertSame('acc_123', ($paymentMethod->getGatewayConfig()?->getConfig() ?? [])[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null);
    }

    public function testRevoke_nonCbGateway_keepsTheHostedFieldsAccountId(): void
    {
        $paymentMethod = $this->paymentMethod(
            factoryName: OneyGatewayFactory::FACTORY_NAME,
            config: [
                PayPlugGatewayFactory::HOSTED_FIELDS => true,
                PayPlugGatewayFactory::HF_IDENTIFIER => 'acc_123',
            ],
        );

        $this->revoker->revoke($paymentMethod);

        self::assertSame('acc_123', ($paymentMethod->getGatewayConfig()?->getConfig() ?? [])[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null);
    }

    public function testRevoke_persistsTheChangeOnce(): void
    {
        $this->entityManager->expects(self::once())->method('flush');

        $this->revoker->revoke($this->paymentMethod());
    }

    public function testRevoke_paymentMethodWithoutGatewayConfig_throws(): void
    {
        $paymentMethod = new PaymentMethod();

        $this->expectException(\LogicException::class);

        $this->revoker->revoke($paymentMethod);
    }

    /**
     * @param array<string, mixed> $config merged over the credentials every connected gateway holds
     */
    private function paymentMethod(
        string $factoryName = PayPlugGatewayFactory::FACTORY_NAME,
        array $config = [],
    ): PaymentMethodInterface {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setConfig([
            'live_client' => ['client_id' => 'client_live', 'client_secret' => 'secret_live'],
            'test_client' => ['client_id' => 'client_test', 'client_secret' => 'secret_test'],
            'account_email' => 'merchant@example.com',
            ...$config,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
