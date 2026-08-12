<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Validator\Constraints;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSavePaymentMethod;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSavePaymentMethodValidator;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * `payplug` and `payplug_oney` are skip-listed: they process card payments
 * directly (or, for Oney, have their own dedicated constraint) and are not alternative payment
 * methods requiring their own per-account enablement flag from the real PayPlug account. Every
 * other factory goes through the real `getAccount()`-backed enablement/live-mode check.
 */
final class IsCanSavePaymentMethodValidatorTest extends ConstraintValidatorTestCase
{
    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    protected function createValidator(): IsCanSavePaymentMethodValidator
    {
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);

        return new IsCanSavePaymentMethodValidator($this->apiClientFactory);
    }

    /**
     * @dataProvider skipListedFactoryProvider
     */
    public function testValidate_skipListedFactory_noViolationAndAccountNeverInspected(string $factoryName): void
    {
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->expects(self::never())->method('getAccount');

        $this->apiClientFactory
            ->expects(self::once())
            ->method('createForPaymentMethod')
            ->willReturn($apiClient)
        ;

        $this->validator->validate($this->buildPaymentMethod($factoryName), new IsCanSavePaymentMethod());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipListedFactoryProvider(): iterable
    {
        yield 'payplug' => [PayPlugGatewayFactory::FACTORY_NAME];
        yield 'payplug_oney' => [OneyGatewayFactory::FACTORY_NAME];
    }

    public function testValidate_nonSkipListedFactory_notEnabledOnAccount_raisesNoAccessViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount([
            'is_live' => true,
            'payment_methods' => [
                'scalapay' => ['enabled' => false],
            ],
        ]);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $constraint = new IsCanSavePaymentMethod();
        $this->validator->validate($this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME), $constraint);

        $this->buildViolation(sprintf($constraint->noAccessMessage, ScalapayGatewayFactory::FACTORY_NAME))
            ->assertRaised()
        ;
    }

    public function testValidate_nonSkipListedFactory_enabledButNotLive_raisesNoTestKeyViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount([
            'is_live' => false,
            'payment_methods' => [
                'scalapay' => ['enabled' => true],
            ],
        ]);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $constraint = new IsCanSavePaymentMethod();
        $this->validator->validate($this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME), $constraint);

        $this->buildViolation(sprintf($constraint->noTestKeyMessage, ScalapayGatewayFactory::FACTORY_NAME))
            ->assertRaised()
        ;
    }

    public function testValidate_nonSkipListedFactory_enabledAndLive_noViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount([
            'is_live' => true,
            'payment_methods' => [
                'scalapay' => ['enabled' => true],
            ],
        ]);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $this->validator->validate($this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME), new IsCanSavePaymentMethod());

        $this->assertNoViolation();
    }

    private function mockApiClientWithAccount(array $account): PayPlugApiClientInterface&MockObject
    {
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willReturn($account);

        return $apiClient;
    }

    private function buildPaymentMethod(string $factoryName): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('isEnabled')->willReturn(true);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection());

        return $paymentMethod;
    }
}
