<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Validator\Constraints;

use Payplug\Exception\ConnectionException;
use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsScalapayAmountRangeValid;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsScalapayAmountRangeValidValidator;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

final class IsScalapayAmountRangeValidValidatorTest extends ConstraintValidatorTestCase
{
    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    protected function createValidator(): IsScalapayAmountRangeValidValidator
    {
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);

        return new IsScalapayAmountRangeValidValidator($this->apiClientFactory, new AccountAmountRangeResolver());
    }

    public function testValidate_nonScalapayFactory_noViolationAndApiNeverCalled(): void
    {
        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $paymentMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME, []);
        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    public function testValidate_noAmountsConfigured_noViolationAndApiNeverCalled(): void
    {
        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, []);
        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    public function testValidate_minGreaterThanMax_raisesViolationWithoutCallingApi(): void
    {
        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 5000, ScalapayGatewayFactory::MAX_AMOUNT => 1000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $constraint = new IsScalapayAmountRangeValid();
        $this->validator->validate($paymentMethod, $constraint);

        $this->buildViolation($constraint->minGreaterThanMaxMessage)->assertRaised();
    }

    public function testValidate_minBelowApiMin_raisesOutOfRangeViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount(500, 200000);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 100];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $constraint = new IsScalapayAmountRangeValid();
        $this->validator->validate($paymentMethod, $constraint);

        $this->buildViolation($constraint->outOfRangeMessage)
            ->setParameter('%min_amount%', '5')
            ->setParameter('%max_amount%', '2000')
            ->assertRaised()
        ;
    }

    public function testValidate_maxAboveApiMax_raisesOutOfRangeViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount(500, 200000);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MAX_AMOUNT => 300000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $constraint = new IsScalapayAmountRangeValid();
        $this->validator->validate($paymentMethod, $constraint);

        $this->buildViolation($constraint->outOfRangeMessage)
            ->setParameter('%min_amount%', '5')
            ->setParameter('%max_amount%', '2000')
            ->assertRaised()
        ;
    }

    public function testValidate_withinApiBounds_noViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount(500, 200000);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 1000, ScalapayGatewayFactory::MAX_AMOUNT => 100000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    public function testValidate_apiThrowsUnauthorizedException_noViolation(): void
    {
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willThrowException(new UnauthorizedException('unauthorized'));
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 1000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    public function testValidate_apiThrowsConnectionException_noViolation(): void
    {
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willThrowException(new ConnectionException('network blip'));
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 1000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    public function testValidate_disabledMethod_noViolationAndApiNeverCalled(): void
    {
        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $config = [ScalapayGatewayFactory::MIN_AMOUNT => 1000, ScalapayGatewayFactory::MAX_AMOUNT => 100000];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config, false);

        $this->validator->validate($paymentMethod, new IsScalapayAmountRangeValid());

        $this->assertNoViolation();
    }

    /**
     * API range: min=500, max=200000 (cents). Merchant sets only max_amount=300, leaving
     * min_amount blank. At checkout, the blank side falls back to the API bound (500), making
     * the *effective* range inverted (500 > 300) even though neither configured value alone
     * looks invalid against its own matching API bound.
     */
    public function testValidate_onlyMaxConfiguredBelowEffectiveMin_raisesMinGreaterThanMaxViolation(): void
    {
        $apiClient = $this->mockApiClientWithAccount(500, 200000);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($apiClient);

        $config = [ScalapayGatewayFactory::MAX_AMOUNT => 300];
        $paymentMethod = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, $config);

        $constraint = new IsScalapayAmountRangeValid();
        $this->validator->validate($paymentMethod, $constraint);

        $this->buildViolation($constraint->minGreaterThanMaxMessage)->assertRaised();
    }

    private function mockApiClientWithAccount(int $minAmount, int $maxAmount): PayPlugApiClientInterface&MockObject
    {
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willReturn([
            'configuration' => [
                'min_amounts' => ['EUR' => $minAmount],
                'max_amounts' => ['EUR' => $maxAmount],
            ],
            'payment_methods' => [],
        ]);

        return $apiClient;
    }

    private function buildPaymentMethod(
        string $factoryName,
        array $config,
        bool $enabled = true,
    ): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('isEnabled')->willReturn($enabled);

        return $paymentMethod;
    }
}
