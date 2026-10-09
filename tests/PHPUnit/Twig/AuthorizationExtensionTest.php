<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Twig;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AuthorizedPaymentOperationProcessor;
use PayPlug\SyliusPayPlugPlugin\Twig\AuthorizationExtension;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationOperatorInterface;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Symfony\Component\Clock\MockClock;

final class AuthorizationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inputNotations(): iterable
    {
        yield 'EUR' => ['EUR', '1234.56'];
        yield 'USD' => ['USD', '1234.56'];
        // A typed amount would be refused in these currencies, so no placeholder suggests one.
        yield 'zero-decimal JPY' => ['JPY', ''];
        yield 'three-decimal KWD' => ['KWD', ''];
        yield 'empty currency' => ['', ''];
        yield 'malformed currency' => ['EU', ''];
    }

    /**
     * @dataProvider inputNotations
     */
    public function testDescribe_exposesTheRemainingAmountInTheNotationTheAmountFieldsAccept(
        string $currency,
        string $expected,
    ): void
    {
        $description = $this->extension()->describe($this->authorizedPayment(123456, $currency));

        self::assertNotNull($description);
        self::assertSame($expected, $description['remaining_amount_input']);
        self::assertSame(123456, $description['remaining_amount']);
    }

    private function extension(): AuthorizationExtension
    {
        $clock = new MockClock('2026-09-24T10:00:00+00:00');

        return new AuthorizationExtension(
            new AuthorizedPaymentOperationProcessor(
                $this->createMock(AuthorizationOperatorInterface::class),
                $this->createMock(ILock::class),
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(StateMachineInterface::class),
                $clock,
                $this->createMock(LoggerInterface::class),
            ),
            $clock,
        );
    }

    private function authorizedPayment(int $amount, string $currency): Payment
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setConfig([
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_1',
            PayPlugGatewayFactory::DEFERRED_CAPTURE => true,
        ]);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setOrder(new Order());
        $payment->setMethod($method);
        $payment->setAmount($amount);
        $payment->setCurrencyCode($currency);
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);
        $payment->setDetails(AuthorizationDetails::open(
            ['hosted_fields_payment_id' => 'pay_1'],
            new PaymentOutput(201, '{}', null, null, null, '2026-09-30T10:00:00+00:00', $amount),
            $amount,
        ));

        return $payment;
    }
}
