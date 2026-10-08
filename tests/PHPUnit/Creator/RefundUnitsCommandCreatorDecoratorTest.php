<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Creator;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Creator\RefundUnitsCommandCreatorDecorator;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\RefundPlugin\Converter\Request\RequestToRefundUnitsConverterInterface;
use Sylius\RefundPlugin\Creator\RequestCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\Model\UnitRefundInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RefundUnitsCommandCreatorDecoratorTest extends TestCase
{
    private const ONEY_PAYMENT_METHOD_ID = 3;

    private const NOT_AVAILABLE_BEFORE_KEY = 'payplug_sylius_payplug_plugin.ui.oney_refund_not_available_before';

    private const PERIOD_EXPIRED_KEY = 'payplug_sylius_payplug_plugin.ui.oney_refund_period_expired';

    /** 2100-01-01 12:00:00 UTC */
    private const FAR_FUTURE_TIMESTAMP = 4102488000;

    /** 2020-01-01 12:00:00 UTC */
    private const PAST_TIMESTAMP = 1577880000;

    private RequestCommandCreatorInterface&MockObject $decorated;

    private RequestToRefundUnitsConverterInterface&MockObject $converter;

    private PaymentMethodRepositoryInterface&MockObject $paymentMethodRepository;

    private OrderRepositoryInterface&MockObject $orderRepository;

    private TranslatorInterface&MockObject $translator;

    private PayPlugApiClientInterface&MockObject $oneyClient;

    private RefundUnitsCommandCreatorDecorator $creator;

    private object $decoratedCommand;

    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        $this->decorated = $this->createMock(RequestCommandCreatorInterface::class);
        $this->converter = $this->createMock(RequestToRefundUnitsConverterInterface::class);
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->oneyClient = $this->createMock(PayPlugApiClientInterface::class);

        $this->decoratedCommand = new \stdClass();
        $this->decorated->method('fromRequest')->willReturn($this->decoratedCommand);
        $this->converter->method('convert')->willReturn([$this->buildUnit(1000)]);
        $this->translator->method('getLocale')->willReturn('en');
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id . '|' . implode('|', $parameters),
        );

        $order = $this->createMock(OrderInterface::class);
        $lastPayment = $this->createMock(PaymentInterface::class);
        $lastPayment->method('getDetails')->willReturn(['payment_id' => 'pay_test_oney']);
        $order->method('getLastPayment')->with(PaymentInterface::STATE_COMPLETED)->willReturn($lastPayment);
        $this->orderRepository->method('findOneByNumber')->with('000000001')->willReturn($order);

        $this->creator = new RefundUnitsCommandCreatorDecorator(
            $this->decorated,
            $this->converter,
            $this->paymentMethodRepository,
            $this->orderRepository,
            $this->translator,
            $this->oneyClient,
        );
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    public function testOneyRefundIsAllowedInsideTheApiWindow(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => time() - 3600, 'refundable_until' => time() + 3600]);

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
    }

    public function testOneyRefundIsAllowedAsSoonAsRefundableAfterIsReached(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => time(), 'refundable_until' => time() + 86400]);

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
    }

    public function testOneyRefundBeforeRefundableAfterIsRefusedWithTheAvailabilityDate(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment([
            'refundable_after' => self::FAR_FUTURE_TIMESTAMP,
            'refundable_until' => self::FAR_FUTURE_TIMESTAMP + 86400,
        ]);
        $this->decorated->expects(self::never())->method('fromRequest');

        $message = $this->catchRefusal();

        self::assertStringStartsWith(self::NOT_AVAILABLE_BEFORE_KEY . '|', $message);
        self::assertStringContainsString('Jan 1, 2100', $message);
        self::assertStringContainsString('12:00', $message);
    }

    public function testOneyRefundAfterRefundableUntilIsRefusedWithTheEndDate(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment([
            'refundable_after' => self::PAST_TIMESTAMP - 86400,
            'refundable_until' => self::PAST_TIMESTAMP,
        ]);
        $this->decorated->expects(self::never())->method('fromRequest');

        $message = $this->catchRefusal();

        self::assertStringStartsWith(self::PERIOD_EXPIRED_KEY . '|', $message);
        self::assertStringContainsString('Jan 1, 2020', $message);
    }

    public function testOneyRefundBeforeRefundableAfterIsRefusedWhenRefundableUntilIsMissing(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => self::FAR_FUTURE_TIMESTAMP]);

        self::assertStringStartsWith(self::NOT_AVAILABLE_BEFORE_KEY . '|', $this->catchRefusal());
    }

    public function testOneyRefundAfterRefundableUntilIsRefusedWhenRefundableAfterIsNull(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => null, 'refundable_until' => self::PAST_TIMESTAMP]);

        self::assertStringStartsWith(self::PERIOD_EXPIRED_KEY . '|', $this->catchRefusal());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMissingBounds(): iterable
    {
        yield 'both bounds absent' => [[]];
        yield 'both bounds null' => [['refundable_after' => null, 'refundable_until' => null]];
        yield 'after null, until in the future' => [['refundable_after' => null, 'refundable_until' => self::FAR_FUTURE_TIMESTAMP]];
        yield 'after in the past, until absent' => [['refundable_after' => self::PAST_TIMESTAMP]];
        yield 'after in the past, until null' => [['refundable_after' => self::PAST_TIMESTAMP, 'refundable_until' => null]];
    }

    /**
     * @dataProvider provideMissingBounds
     *
     * @param array<string, mixed> $attributes
     */
    public function testMissingBoundDoesNotRestrictThatSideOfTheWindow(array $attributes): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment($attributes);

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
    }

    public function testDateIsFormattedInTheTranslatorLocaleAndTheDefaultTimezone(): void
    {
        date_default_timezone_set('Europe/Paris');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('fr');
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id . '|' . implode('|', $parameters),
        );
        $this->creator = new RefundUnitsCommandCreatorDecorator(
            $this->decorated,
            $this->converter,
            $this->paymentMethodRepository,
            $this->orderRepository,
            $translator,
            $this->oneyClient,
        );
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => self::FAR_FUTURE_TIMESTAMP]);

        $message = $this->catchRefusal();

        self::assertStringContainsString('1 janv. 2100', $message);
        self::assertStringContainsString('13:00', $message);
    }

    public function testSuccessiveOneyRefundsInsideTheWindowAreNotBlocked(): void
    {
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => time() - 60, 'refundable_until' => time() + 86400]);

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
    }

    public function testMinimumRefundAmountStillAppliesInsideTheOneyWindow(): void
    {
        $converter = $this->createMock(RequestToRefundUnitsConverterInterface::class);
        $converter->method('convert')->willReturn([$this->buildUnit(9)]);
        $this->creator = new RefundUnitsCommandCreatorDecorator(
            $this->decorated,
            $converter,
            $this->paymentMethodRepository,
            $this->orderRepository,
            $this->translator,
            $this->oneyClient,
        );
        $this->givenPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $this->givenOneyPayment(['refundable_after' => time() - 60, 'refundable_until' => time() + 86400]);
        $this->decorated->expects(self::never())->method('fromRequest');

        self::assertStringStartsWith(
            'payplug_sylius_payplug_plugin.ui.refund_minimum_amount_requirement_not_met',
            $this->catchRefusal(),
        );
    }

    public function testNonOneyPayPlugGatewayNeverRetrievesThePayment(): void
    {
        $this->givenPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $this->oneyClient->expects(self::never())->method('retrieve');

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest()));
    }

    public function testRefundWithoutPaymentMethodDelegatesWithoutRetrievingThePayment(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('find');
        $this->oneyClient->expects(self::never())->method('retrieve');

        self::assertSame($this->decoratedCommand, $this->creator->fromRequest($this->buildRequest(0)));
    }

    private function givenPaymentMethod(string $factoryName): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->paymentMethodRepository
            ->method('find')
            ->with(self::ONEY_PAYMENT_METHOD_ID)
            ->willReturn($paymentMethod)
        ;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function givenOneyPayment(array $attributes): void
    {
        $this->oneyClient
            ->method('retrieve')
            ->with('pay_test_oney')
            ->willReturn(Payment::fromAttributes(['id' => 'pay_test_oney'] + $attributes))
        ;
    }

    private function buildRequest(int $paymentMethodId = self::ONEY_PAYMENT_METHOD_ID): Request
    {
        return new Request(
            [],
            ['sylius_refund_payment_method' => (string) $paymentMethodId],
            ['orderNumber' => '000000001'],
        );
    }

    private function buildUnit(int $total): UnitRefundInterface
    {
        $unit = $this->createMock(UnitRefundInterface::class);
        $unit->method('total')->willReturn($total);

        return $unit;
    }

    private function catchRefusal(): string
    {
        try {
            $this->creator->fromRequest($this->buildRequest());
        } catch (InvalidRefundAmount $exception) {
            return $exception->getMessage();
        }

        self::fail('The refund was expected to be refused.');
    }
}
