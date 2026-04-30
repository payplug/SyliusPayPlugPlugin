<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Validator;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PaymentMethodValidatorTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private ValidatorInterface&MockObject $validator;

    private EntityManagerInterface&MockObject $entityManager;

    private PaymentMethodValidator $paymentMethodValidator;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->paymentMethodValidator = new PaymentMethodValidator(
            $this->requestStack,
            $this->validator,
            $this->entityManager,
        );
    }

    // -------------------------------------------------------------------------
    // process() — null gateway config → early return, no flush
    // -------------------------------------------------------------------------

    /**
     * getGatewayConfig() returns null (payment method not yet fully configured).
     * Verifies process() exits immediately: validator and EntityManager are never called.
     */
    public function testProcess_nullGatewayConfig_returnsEarlyWithoutFlush(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn(null);

        $this->entityManager->expects(self::never())->method('flush');
        $this->validator->expects(self::never())->method('validate');

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // process() — unsupported factory name → InvalidArgumentException
    // -------------------------------------------------------------------------

    /**
     * The gateway config declares a factory name not handled by the validator's match statement.
     * Verifies process() throws an InvalidArgumentException.
     */
    public function testProcess_unsupportedFactory_throwsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $paymentMethod = $this->buildPaymentMethod('unsupported_factory', []);

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // process() — 0 violations → no flash, method stays enabled, flush called
    // -------------------------------------------------------------------------

    /**
     * Validation returns an empty ConstraintViolationList (all API permissions are satisfied).
     * Verifies the method is not disabled, no flash is added, and flush() is called once.
     */
    public function testProcess_noViolations_doesNotDisableOrFlashError(): void
    {
        $paymentMethod = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME, []);

        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $paymentMethod->expects(self::never())->method('disable');

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects(self::never())->method('add');
        $session = $this->createMock(Session::class);
        $session->method('getFlashBag')->willReturn($flashBag);
        $this->requestStack->method('getSession')->willReturn($session);

        $this->entityManager->expects(self::once())->method('flush');

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // process() — violations → flash each + disabled message, method disabled, flush called
    // -------------------------------------------------------------------------

    /**
     * Validation returns one violation (e.g. Oney feature not enabled on the account).
     * Verifies disable() is called, two flash messages are added (violation + disabled notice), and flush() runs.
     */
    public function testProcess_withViolations_disablesMethodAndFlashesErrors(): void
    {
        $paymentMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME, []);

        $violation = $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class);
        $violation->method('getMessage')->willReturn('Oney not enabled');

        $violationList = new ConstraintViolationList([$violation]);
        $this->validator->method('validate')->willReturn($violationList);

        $flashBag = $this->createMock(FlashBagInterface::class);
        // Should be called twice: once for the violation message, once for "payment_method_disabled"
        $flashBag->expects(self::exactly(2))->method('add')->with('payplug_error', self::anything());
        $session = $this->createMock(Session::class);
        $session->method('getFlashBag')->willReturn($flashBag);
        $this->requestStack->method('getSession')->willReturn($session);

        $paymentMethod->expects(self::once())->method('disable');
        $this->entityManager->expects(self::once())->method('flush');

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // process() — PayPlug factory, no special flags → only IsCanSavePaymentMethod constraint
    // -------------------------------------------------------------------------

    /**
     * PayPlug gateway with ONE_CLICK, DEFERRED_CAPTURE and INTEGRATED_PAYMENT all false.
     * Verifies only the base IsCanSavePaymentMethod constraint (1 total) is passed to the validator.
     */
    public function testProcess_payplugFactory_noFlags_validatesWithBaseConstraintOnly(): void
    {
        $config = [
            PayPlugGatewayFactory::ONE_CLICK => false,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => false,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
        ];
        $paymentMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, $config);

        $this->validator
            ->expects(self::once())
            ->method('validate')
            ->willReturnCallback(function ($subject, array $constraints) {
                // Only the base IsCanSavePaymentMethod constraint (no permission constraints)
                self::assertCount(1, $constraints);

                return new ConstraintViolationList();
            })
        ;

        $flashBag = $this->createMock(FlashBagInterface::class);
        $session = $this->createMock(Session::class);
        $session->method('getFlashBag')->willReturn($flashBag);
        $this->requestStack->method('getSession')->willReturn($session);

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // process() — PayPlug factory, all flags enabled → 4 constraints (base + 3 permissions)
    // -------------------------------------------------------------------------

    /**
     * PayPlug gateway with ONE_CLICK, DEFERRED_CAPTURE and INTEGRATED_PAYMENT all true.
     * Verifies 4 constraints are passed to the validator (base + one per enabled feature flag).
     */
    public function testProcess_payplugFactory_allFlagsEnabled_validatesWithAllConstraints(): void
    {
        $config = [
            PayPlugGatewayFactory::ONE_CLICK => true,
            PayPlugGatewayFactory::DEFERRED_CAPTURE => true,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => true,
        ];
        $paymentMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, $config);

        $this->validator
            ->expects(self::once())
            ->method('validate')
            ->willReturnCallback(function ($subject, array $constraints) {
                // Base + CAN_SAVE_CARD + CAN_CREATE_DEFERRED_PAYMENT + CAN_USE_INTEGRATED_PAYMENTS
                self::assertCount(4, $constraints);

                return new ConstraintViolationList();
            })
        ;

        $flashBag = $this->createMock(FlashBagInterface::class);
        $session = $this->createMock(Session::class);
        $session->method('getFlashBag')->willReturn($flashBag);
        $this->requestStack->method('getSession')->willReturn($session);

        $this->paymentMethodValidator->process($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPaymentMethod(string $factoryName, array $config): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }
}
