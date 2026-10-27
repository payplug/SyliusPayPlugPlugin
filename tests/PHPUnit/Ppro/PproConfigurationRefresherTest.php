<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Exception\ConnectionException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresher;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\Clock\MockClock;

final class PproConfigurationRefresherTest extends TestCase
{
    private const NOW = '2026-10-20T09:00:00+00:00';

    private PayPlugApiClientFactoryInterface&MockObject $clientFactory;

    private PayPlugApiClientInterface&MockObject $client;

    private EntityManagerInterface&MockObject $entityManager;

    private LoggerInterface&MockObject $logger;

    private PproConfigurationRefresher $refresher;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->client = $this->createMock(PayPlugApiClientInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientFactory->method('createForPaymentMethod')->willReturn($this->client);

        $this->refresher = new PproConfigurationRefresher(
            $this->clientFactory,
            new AccountAmountRangeResolver(),
            new AccountAllowedCountriesResolver(),
            $this->entityManager,
            new MockClock(self::NOW),
            $this->logger,
        );
    }

    public function testCapturesBancontactEntry(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME, ['live' => true]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->refreshed);
        self::assertFalse($result->adjusted);
        self::assertSame([
            'name' => 'bancontact',
            'enabled' => true,
            'allowed_countries' => null,
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => ['min' => null, 'max' => null],
            'configured_countries' => null,
            'live' => true,
            'refreshed_at' => self::NOW,
        ], $this->storedBlock($paymentMethod));
    }

    public function testCapturesWeroCountries(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['BE', 'DE'], $this->storedBlock($paymentMethod)['allowed_countries']);
        self::assertFalse($this->storedBlock($paymentMethod)['live']);
    }

    public function testCapturesScalapayOwnBounds(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 500, 'max' => 200000], $this->storedBlock($paymentMethod)['default_amount']);
    }

    public function testIgnoresGiropayAndSofortKeys(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        self::assertArrayNotHasKey('giropay', $config);
        self::assertArrayNotHasKey('sofort', $config);
        self::assertSame('wero', $this->storedBlock($paymentMethod)['name']);
    }

    public function testMissingAllowedCountriesKeyMeansNull(): void
    {
        $account = self::sampleAccount();
        unset($account['payment_methods']['wero']['allowed_countries']);
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        self::assertNull($this->storedBlock($paymentMethod)['allowed_countries']);
    }

    public function testEmptyAllowedCountriesMeansNull(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['allowed_countries'] = [];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        self::assertNull($this->storedBlock($paymentMethod)['allowed_countries']);
    }

    public function testDisabledByApiIsStored(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['enabled'] = false;
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $this->refresher->refresh($paymentMethod);

        self::assertFalse($this->storedBlock($paymentMethod)['enabled']);
    }

    public function testPayloadWithoutEurBoundsLeavesDefaultAmountNull(): void
    {
        $account = self::sampleAccount();
        $account['configuration']['min_amounts'] = ['USD' => 30];
        $account['configuration']['max_amounts'] = ['USD' => 2000000];
        $account['payment_methods']['wero']['min_amounts'] = ['USD' => 30];
        $account['payment_methods']['wero']['max_amounts'] = ['USD' => 2000000];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->refreshed);
        self::assertNull($this->storedBlock($paymentMethod)['default_amount']);
        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testCallsGetAccountWithRefreshTrue(): void
    {
        $this->client->expects(self::once())->method('getAccount')->with(true)->willReturn(self::sampleAccount());

        $this->refresher->refresh($this->paymentMethod(WeroGatewayFactory::FACTORY_NAME));
    }

    public function testFlushesByDefault(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $this->entityManager->expects(self::once())->method('flush');

        $this->refresher->refresh($this->paymentMethod(WeroGatewayFactory::FACTORY_NAME));
    }

    public function testDoesNotFlushWhenAskedNotTo(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $this->entityManager->expects(self::never())->method('flush');

        $result = $this->refresher->refresh($this->paymentMethod(WeroGatewayFactory::FACTORY_NAME), false);

        self::assertTrue($result->refreshed);
    }

    public function testKeepsConfiguredValuesInsideNewBounds(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 1000, 'max' => 50000], ['BE']),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertFalse($result->adjusted);
        self::assertSame(['min' => 1000, 'max' => 50000], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertSame(['BE'], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    /**
     * An empty side means "follow the PayPlug limit": it is never replaced by a snapshot of the
     * current bound, so a limit PayPlug widens later applies without any merchant action.
     */
    public function testKeepsAnEmptyConfiguredSideEmpty(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 1000, 'max' => null]),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertFalse($result->adjusted);
        self::assertSame(['min' => 1000, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testEmptySidesFollowAWidenedApiLimit(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['max_amounts'] = ['EUR' => 5000000];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => null, 'max' => null]),
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 30, 'max' => 5000000], $this->storedBlock($paymentMethod)['default_amount']);
        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    /**
     * Only the max is set and PayPlug raises its min above it: the effective range is empty, so the
     * merchant range is reset to follow PayPlug.
     */
    public function testResetsAOneSidedRangeLeftEmptyByANewApiMin(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['min_amounts'] = ['EUR' => 60000];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => null, 'max' => 50000]),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->adjusted);
        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testClampsConfiguredAmountToNewDefaultBounds(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['min_amounts'] = ['EUR' => 2000];
        $account['payment_methods']['wero']['max_amounts'] = ['EUR' => 40000];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 1000, 'max' => 50000]),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->adjusted);
        self::assertSame(['min' => 2000, 'max' => 40000], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testResetsConfiguredAmountWhenClampedRangeIsEmpty(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['min_amounts'] = ['EUR' => 60000];
        $account['payment_methods']['wero']['max_amounts'] = ['EUR' => 90000];
        $this->client->method('getAccount')->willReturn($account);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 1000, 'max' => 50000]),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->adjusted);
        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testIntersectsConfiguredCountriesWithNewAllowed(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 30, 'max' => 2000000], ['FR', 'BE']),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->adjusted);
        self::assertSame(['BE'], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    /**
     * None of the merchant's countries is allowed any more: the method is hidden until the merchant
     * picks new ones, rather than widened to every country PayPlug allows.
     */
    public function testEmptyCountryIntersectionHidesTheMethod(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 30, 'max' => 2000000], ['FR', 'IT']),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertTrue($result->adjusted);
        self::assertSame([], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    public function testKeepsConfiguredCountriesWhenApiHasNoRestriction(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME, [
            'payment_method' => self::previousBlock(['min' => 30, 'max' => 2000000], ['FR', 'IT']),
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertFalse($result->adjusted);
        self::assertSame(['FR', 'IT'], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    public function testNeverOverwritesConfiguredValuesOtherwise(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $previous = self::previousBlock(['min' => 30, 'max' => 2000000], ['DE']);
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, ['payment_method' => $previous]);

        $this->refresher->refresh($paymentMethod);
        $this->refresher->refresh($paymentMethod);

        self::assertSame($previous['configured_amount'], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertSame(['DE'], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    public function testScalapayLegacyRangeIsCarriedOverOnFirstCapture(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 5000,
            'max_amount' => 90000,
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 5000, 'max' => 90000], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertFalse($result->adjusted);
    }

    public function testScalapayLegacyRangeIsClampedToThePayPlugLimits(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 100,
            'max_amount' => 900000,
        ]);

        $result = $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 500, 'max' => 200000], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertTrue($result->adjusted);
    }

    public function testScalapayLegacySideOfUnexpectedTypeIsDropped(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => '5000',
            'max_amount' => 90000,
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => null, 'max' => 90000], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testScalapayLegacyRangeIsNotReadOnceABlockIsStored(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 5000,
            'max_amount' => 90000,
            'payment_method' => self::previousBlock(['min' => null, 'max' => null]),
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    /**
     * Countries saved before the first successful refresh create the block without an amount range:
     * the legacy range is still carried over, since the form never showed it.
     */
    public function testScalapayLegacyRangeIsCarriedOverWhenOnlyCountriesWereSaved(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 5000,
            'max_amount' => 90000,
            'payment_method' => ['configured_amount' => ['min' => null, 'max' => null], 'configured_countries' => ['FR']],
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 5000, 'max' => 90000], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertSame(['FR'], $this->storedBlock($paymentMethod)['configured_countries']);
    }

    public function testScalapayLegacyRangeIsNotReadOverAnEnteredRange(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 5000,
            'max_amount' => 90000,
            'payment_method' => ['configured_amount' => ['min' => 1000, 'max' => null]],
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => 1000, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    public function testLegacyRangeKeysAreOnlyReadForScalapay(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'min_amount' => 5000,
            'max_amount' => 90000,
        ]);

        $this->refresher->refresh($paymentMethod);

        self::assertSame(['min' => null, 'max' => null], $this->storedBlock($paymentMethod)['configured_amount']);
    }

    /**
     * The PayPlug call can take a while: a save of the gateway config made meanwhile (credentials,
     * merchant settings) is read back before writing, and only the `payment_method` block is replaced.
     */
    public function testReReadsTheGatewayConfigBeforeWriting(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'live' => true,
            'account_email' => 'old@example.com',
        ]);
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        self::assertNotNull($gatewayConfig);

        $this->entityManager->expects(self::once())->method('refresh')->with($gatewayConfig)->willReturnCallback(
            static function (GatewayConfig $gatewayConfig): void {
                $gatewayConfig->setConfig([
                    'live' => true,
                    'account_email' => 'new@example.com',
                    'payment_method' => self::previousBlock(['min' => 1000, 'max' => 50000], ['BE']),
                ]);
            },
        );

        $this->refresher->refresh($paymentMethod);

        $config = $gatewayConfig->getConfig();
        self::assertSame('new@example.com', $config['account_email']);
        self::assertSame(['min' => 1000, 'max' => 50000], $this->storedBlock($paymentMethod)['configured_amount']);
        self::assertSame(['BE'], $this->storedBlock($paymentMethod)['configured_countries']);
        self::assertSame(self::NOW, $this->storedBlock($paymentMethod)['refreshed_at']);
    }

    public function testLogsAnEmptyAmountRangeWhenStoringIt(): void
    {
        $account = self::sampleAccount();
        $account['payment_methods']['wero']['min_amounts'] = ['EUR' => 5000];
        $account['payment_methods']['wero']['max_amounts'] = ['EUR' => 1000];
        $this->client->method('getAccount')->willReturn($account);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('amount range is empty'));

        $result = $this->refresher->refresh($this->paymentMethod(WeroGatewayFactory::FACTORY_NAME));

        self::assertTrue($result->refreshed);
    }

    public function testRefreshIfStaleSkipsARecentRefresh(): void
    {
        $initial = ['live' => true, 'payment_method' => self::refreshedBlock('2026-10-20T08:55:01+00:00', true)];
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, $initial);
        $this->clientFactory->expects(self::never())->method('createForPaymentMethod');
        $this->entityManager->expects(self::never())->method('flush');

        $result = $this->refresher->refreshIfStale($paymentMethod);

        self::assertTrue($result->refreshed);
        self::assertTrue($result->skipped);
        self::assertFalse($result->adjusted);
        self::assertSame($initial, $paymentMethod->getGatewayConfig()?->getConfig());
    }

    /**
     * @dataProvider staleConfigs
     *
     * @param array<string, mixed> $initial
     */
    public function testRefreshIfStaleRefreshesStaleLimits(array $initial): void
    {
        $this->client->expects(self::once())->method('getAccount')->willReturn(self::sampleAccount());
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, $initial);

        $result = $this->refresher->refreshIfStale($paymentMethod);

        self::assertTrue($result->refreshed);
        self::assertFalse($result->skipped);
        self::assertSame(self::NOW, $this->storedBlock($paymentMethod)['refreshed_at']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function staleConfigs(): iterable
    {
        yield 'older than five minutes' => [['live' => true, 'payment_method' => self::refreshedBlock('2026-10-20T08:54:59+00:00', true)]];
        yield 'fetched in the other environment' => [['live' => false, 'payment_method' => self::refreshedBlock('2026-10-20T08:59:00+00:00', true)]];
        yield 'never refreshed' => [['live' => true]];
        yield 'merchant settings only' => [['live' => true, 'payment_method' => ['configured_amount' => ['min' => 1000, 'max' => null]]]];
        yield 'unreadable timestamp' => [['live' => true, 'payment_method' => self::refreshedBlock('yesterday', true)]];
    }

    public function testRefreshIfStaleOnANonPproMethodIsNotRefreshed(): void
    {
        $this->clientFactory->expects(self::never())->method('createForPaymentMethod');

        $result = $this->refresher->refreshIfStale($this->paymentMethod(OneyGatewayFactory::FACTORY_NAME));

        self::assertFalse($result->refreshed);
    }

    public function testUnrelatedConfigKeysSurvive(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $initial = [
            'live' => true,
            'live_client' => ['client_id' => 'live_id'],
            'test_client' => ['client_id' => 'test_id'],
            'account_email' => 'merchant@example.com',
        ];
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, $initial);

        $this->refresher->refresh($paymentMethod);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        foreach ($initial as $key => $value) {
            self::assertSame($value, $config[$key]);
        }
    }

    public function testGatewayConfigurationExceptionFromFactory(): void
    {
        $clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $clientFactory->method('createForPaymentMethod')->willThrowException(new GatewayConfigurationException('no client'));
        $refresher = new PproConfigurationRefresher(
            $clientFactory,
            new AccountAmountRangeResolver(),
            new AccountAllowedCountriesResolver(),
            $this->entityManager,
            new MockClock(self::NOW),
            $this->logger,
        );

        $this->assertFailureLeavesConfigUntouched($refresher);
    }

    public function testSdkExceptionFromGetAccount(): void
    {
        $this->client->method('getAccount')->willThrowException(new ConnectionException('timeout'));

        $this->assertFailureLeavesConfigUntouched($this->refresher);
    }

    public function testPayloadWithoutTheMethodEntry(): void
    {
        $account = self::sampleAccount();
        unset($account['payment_methods']['wero']);
        $this->client->method('getAccount')->willReturn($account);

        $this->assertFailureLeavesConfigUntouched($this->refresher);
    }

    /**
     * @dataProvider malformedAccounts
     */
    public function testMalformedPayload(array $account): void
    {
        $this->client->method('getAccount')->willReturn($account);

        $this->assertFailureLeavesConfigUntouched($this->refresher);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function malformedAccounts(): iterable
    {
        $account = self::sampleAccount();

        yield 'empty payload' => [[]];
        yield 'payment_methods is a string' => [['payment_methods' => 'wero']];

        $countries = $account;
        $countries['payment_methods']['wero']['allowed_countries'] = 'BE';
        yield 'allowed_countries is a string' => [$countries];

        $amounts = $account;
        $amounts['payment_methods']['wero']['min_amounts'] = ['EUR' => '30'];
        yield 'min amount is a string' => [$amounts];

        $enabled = $account;
        $enabled['payment_methods']['wero']['enabled'] = 'yes';
        yield 'enabled is a string' => [$enabled];
    }

    public function testFlushFailureIsReportedAsAFailure(): void
    {
        $this->client->method('getAccount')->willReturn(self::sampleAccount());
        $this->entityManager->method('flush')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('warning');

        $result = $this->refresher->refresh($this->paymentMethod(WeroGatewayFactory::FACTORY_NAME));

        self::assertFalse($result->refreshed);
    }

    public function testNonPproFactoryReturnsFalseWithoutCallingTheApi(): void
    {
        $this->clientFactory->expects(self::never())->method('createForPaymentMethod');
        $this->logger->expects(self::never())->method('warning');

        $result = $this->refresher->refresh($this->paymentMethod(OneyGatewayFactory::FACTORY_NAME));

        self::assertFalse($result->refreshed);
        self::assertFalse($result->adjusted);
    }

    public function testPaymentMethodWithoutGatewayConfigReturnsFalse(): void
    {
        $this->clientFactory->expects(self::never())->method('createForPaymentMethod');

        $result = $this->refresher->refresh(new PaymentMethod());

        self::assertFalse($result->refreshed);
    }

    private function assertFailureLeavesConfigUntouched(PproConfigurationRefresher $refresher): void
    {
        $initial = ['live' => true, 'payment_method' => self::previousBlock(['min' => 1000, 'max' => 50000], ['BE'])];
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, $initial);

        $this->logger->expects(self::once())->method('warning');
        $this->entityManager->expects(self::never())->method('flush');

        $result = $refresher->refresh($paymentMethod);

        self::assertFalse($result->refreshed);
        self::assertSame($initial, $paymentMethod->getGatewayConfig()?->getConfig());
    }

    /**
     * @param array{min: int|null, max: int|null} $configuredAmount
     * @param list<string>|null $configuredCountries
     *
     * @return array<string, mixed>
     */
    private static function previousBlock(array $configuredAmount, ?array $configuredCountries = null): array
    {
        return [
            'name' => 'wero',
            'enabled' => true,
            'allowed_countries' => ['BE', 'DE'],
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => $configuredAmount,
            'configured_countries' => $configuredCountries,
            'live' => true,
            'refreshed_at' => '2026-10-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function refreshedBlock(string $refreshedAt, bool $live): array
    {
        return array_merge(self::previousBlock(['min' => null, 'max' => null]), [
            'live' => $live,
            'refreshed_at' => $refreshedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedBlock(PaymentMethod $paymentMethod): array
    {
        $block = ($paymentMethod->getGatewayConfig()?->getConfig() ?? [])['payment_method'] ?? null;
        self::assertIsArray($block);

        return $block;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(string $factoryName, array $config = []): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($factoryName);
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }

    /**
     * Redacted `/account` payload captured on a live PPRO account.
     *
     * @return array<string, mixed>
     */
    private static function sampleAccount(): array
    {
        return [
            'object' => 'account',
            'is_live' => true,
            'configuration' => [
                'currencies' => ['EUR'],
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'apple_pay' => ['enabled' => true, 'allowed_domain_names' => ['shop.example.com']],
                'american_express' => ['enabled' => true],
                'bancontact' => ['enabled' => true, 'allowed_countries' => ['ALL'], 'min_amounts' => ['EUR' => 30], 'max_amounts' => ['EUR' => 2000000]],
                'giropay' => ['enabled' => true, 'allowed_countries' => ['DE'], 'min_amounts' => ['EUR' => 100], 'max_amounts' => ['EUR' => 1000000]],
                'sofort' => ['enabled' => true, 'allowed_countries' => ['AT', 'BE', 'DE', 'ES', 'NL'], 'min_amounts' => ['EUR' => 100], 'max_amounts' => ['EUR' => 500000]],
                'wero' => ['enabled' => true, 'allowed_countries' => ['BE', 'DE'], 'min_amounts' => ['EUR' => 30], 'max_amounts' => ['EUR' => 2000000]],
                'scalapay' => ['enabled' => true, 'allowed_countries' => ['AT', 'BE', 'DE', 'ES', 'FI', 'FR', 'IT', 'NL', 'PT'], 'min_amounts' => ['EUR' => 500], 'max_amounts' => ['EUR' => 200000], 'merchant_token' => 'redacted'],
            ],
        ];
    }
}
