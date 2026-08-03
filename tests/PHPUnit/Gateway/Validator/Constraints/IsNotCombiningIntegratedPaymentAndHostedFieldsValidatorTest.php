<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Validator\Constraints;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsNotCombiningIntegratedPaymentAndHostedFields;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsNotCombiningIntegratedPaymentAndHostedFieldsValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class IsNotCombiningIntegratedPaymentAndHostedFieldsValidatorTest extends TestCase
{
    private ExecutionContextInterface&MockObject $context;

    private IsNotCombiningIntegratedPaymentAndHostedFieldsValidator $validator;

    protected function setUp(): void
    {
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new IsNotCombiningIntegratedPaymentAndHostedFieldsValidator();
        $this->validator->initialize($this->context);
    }

    public function testBothFlagsEnabled_buildsViolation(): void
    {
        $paymentMethod = $this->buildPaymentMethod([
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => true,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
        ]);

        $violationBuilder = $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('addViolation');
        $this->context->expects(self::once())->method('buildViolation')->willReturn($violationBuilder);

        $this->validator->validate($paymentMethod, new IsNotCombiningIntegratedPaymentAndHostedFields());
    }

    public function testOnlyHostedFieldsEnabled_noViolation(): void
    {
        $paymentMethod = $this->buildPaymentMethod([
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
        ]);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate($paymentMethod, new IsNotCombiningIntegratedPaymentAndHostedFields());
    }

    public function testNeitherFlagEnabled_noViolation(): void
    {
        $paymentMethod = $this->buildPaymentMethod([
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
        ]);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate($paymentMethod, new IsNotCombiningIntegratedPaymentAndHostedFields());
    }

    private function buildPaymentMethod(array $config): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }
}
