<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Console;

use PayPlug\SyliusPayPlugPlugin\Console\Command\RefreshPproConfigurationCommand;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefreshResult;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshPproConfigurationCommandTest extends TestCase
{
    private PaymentMethodRepositoryInterface&MockObject $repository;

    private PproConfigurationRefresherInterface&MockObject $refresher;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->refresher = $this->createMock(PproConfigurationRefresherInterface::class);

        $this->tester = new CommandTester(new RefreshPproConfigurationCommand($this->repository, $this->refresher));
    }

    public function testIsNamedPayplugPproRefresh(): void
    {
        self::assertSame('payplug:ppro:refresh', (new RefreshPproConfigurationCommand($this->repository, $this->refresher))->getName());
    }

    public function testRefreshesEveryPproPaymentMethod(): void
    {
        $bancontact = $this->paymentMethod('bancontact', BancontactGatewayFactory::FACTORY_NAME);
        $wero = $this->paymentMethod('wero', WeroGatewayFactory::FACTORY_NAME);
        $scalapay = $this->paymentMethod('scalapay', ScalapayGatewayFactory::FACTORY_NAME, enabled: false);
        $this->repository->method('findAll')->willReturn([$bancontact, $wero, $scalapay]);

        $refreshed = [];
        $this->refresher->expects(self::exactly(3))->method('refresh')->willReturnCallback(
            static function (PaymentMethod $paymentMethod) use (&$refreshed): PproRefreshResult {
                $refreshed[] = $paymentMethod->getCode();

                return new PproRefreshResult(true);
            },
        );

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertSame(['bancontact', 'wero', 'scalapay'], $refreshed);
        self::assertStringContainsString('wero', $this->tester->getDisplay());
    }

    public function testSkipsNonPproMethods(): void
    {
        $this->repository->method('findAll')->willReturn([
            $this->paymentMethod('card', PayPlugGatewayFactory::FACTORY_NAME),
            $this->paymentMethod('oney', OneyGatewayFactory::FACTORY_NAME),
            new PaymentMethod(),
            $this->paymentMethod('wero', WeroGatewayFactory::FACTORY_NAME),
        ]);
        $this->refresher->expects(self::once())->method('refresh')->willReturn(new PproRefreshResult(true));

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
    }

    public function testReportsFailuresAndExitsNonZeroWhenAnyFails(): void
    {
        $this->repository->method('findAll')->willReturn([
            $this->paymentMethod('bancontact', BancontactGatewayFactory::FACTORY_NAME),
            $this->paymentMethod('wero', WeroGatewayFactory::FACTORY_NAME),
        ]);
        $this->refresher->method('refresh')->willReturnOnConsecutiveCalls(
            PproRefreshResult::notRefreshed(),
            new PproRefreshResult(true),
        );

        self::assertSame(Command::FAILURE, $this->tester->execute([]));
        self::assertStringContainsString('bancontact', $this->tester->getDisplay());
    }

    public function testReportsNeverConnectedMethodsAsSkipped(): void
    {
        $this->repository->method('findAll')->willReturn([
            $this->paymentMethod('bancontact', BancontactGatewayFactory::FACTORY_NAME, connected: false),
            $this->paymentMethod('wero', WeroGatewayFactory::FACTORY_NAME),
        ]);
        $this->refresher->expects(self::once())->method('refresh')->willReturn(new PproRefreshResult(true));

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('Skipped "bancontact"', $this->tester->getDisplay());
    }

    public function testDoesNothingWhenNoPproMethodExists(): void
    {
        $this->repository->method('findAll')->willReturn([$this->paymentMethod('card', PayPlugGatewayFactory::FACTORY_NAME)]);
        $this->refresher->expects(self::never())->method('refresh');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
    }

    private function paymentMethod(
        string $code,
        string $factoryName,
        bool $enabled = true,
        bool $connected = true,
    ): PaymentMethod {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setConfig($connected ? ['test_client' => ['client_id' => 'id', 'client_secret' => 'secret']] : []);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($code);
        $paymentMethod->setEnabled($enabled);
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
