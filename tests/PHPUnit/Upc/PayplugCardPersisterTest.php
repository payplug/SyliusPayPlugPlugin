<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Upc\PayplugCardPersister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface as CorePaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class PayplugCardPersisterTest extends TestCase
{
    private FactoryInterface&MockObject $payplugCardFactory;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private ManagerRegistry&MockObject $managerRegistry;

    private PayplugCardPersister $persister;

    protected function setUp(): void
    {
        $this->payplugCardFactory = $this->createMock(FactoryInterface::class);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);

        $this->persister = new PayplugCardPersister($this->payplugCardFactory, $this->payplugCardRepository, $this->managerRegistry);
    }

    private function paymentWithOrder(?CustomerInterface $customer): PaymentInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }

    private function corePaymentMethod(bool $live = false): CorePaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['live' => $live]);

        $method = $this->createMock(CorePaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }

    public function testPersist_withNoCustomerOnTheOrder_doesNotPersistACard(): void
    {
        $payment = $this->paymentWithOrder(null);

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->persister->persist('alias_1', $payment, $this->corePaymentMethod(), [], []);
    }

    public function testPersist_withMethodNotACorePaymentMethod_doesNotPersistACard(): void
    {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));
        $method = $this->createMock(PaymentMethodInterface::class);

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->persister->persist('alias_1', $payment, $method, [], []);
    }

    public function testPersist_withAliasAlreadyStored_doesNotPersistADuplicate(): void
    {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));

        $this->payplugCardRepository->method('findOneBy')
            ->with(['externalId' => 'alias_1', 'isLive' => false])
            ->willReturn(new Card());
        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->persister->persist('alias_1', $payment, $this->corePaymentMethod(), [], []);
    }

    public function testPersist_whenAddLosesARaceAgainstAConcurrentPersistCallForTheSameAlias_doesNotThrow(): void
    {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->method('add')->with($card)
            ->willThrowException($this->createMock(UniqueConstraintViolationException::class));
        $this->managerRegistry->expects(self::once())->method('resetManager');

        $this->persister->persist('alias_1', $payment, $this->corePaymentMethod(), [], []);

        $this->addToAssertionCount(1);
    }

    public function testPersist_withFetchedCardDataAvailable_takesPrecedenceOverDetailsFallback(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $payment = $this->paymentWithOrder($customer);
        $method = $this->corePaymentMethod(live: true);

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->persister->persist(
            'alias_1',
            $payment,
            $method,
            [
                'hosted_fields_selected_brand' => 'CB',
                'hosted_fields_last4' => '0000',
                'hosted_fields_expiration_month' => 1,
                'hosted_fields_expiration_year' => 2020,
                'hosted_fields_country' => 'DE',
            ],
            [
                'brand' => 'VISA',
                'last4' => '4242',
                'expirationMonth' => 12,
                'expirationYear' => 2030,
            ],
        );

        self::assertSame($customer, $card->getCustomer());
        self::assertSame('alias_1', $card->getExternalId());
        self::assertSame('VISA', $card->getBrand());
        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2030, $card->getExpirationYear());
        // No card country field exists on the operation resource, so it always comes from $details.
        self::assertSame('DE', $card->getCountryCode());
        self::assertTrue($card->isLive());
        self::assertSame($method, $card->getPaymentMethod());
    }

    public function testPersist_withNoFetchedCardDataAndNoDetails_usesEmptyDefaults(): void
    {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);

        $this->persister->persist('alias_1', $payment, $this->corePaymentMethod(), [], []);

        self::assertSame('', $card->getBrand());
        self::assertSame('', $card->getLast4());
        self::assertSame(0, $card->getExpirationMonth());
        self::assertSame(0, $card->getExpirationYear());
        self::assertSame('', $card->getCountryCode());
    }

    public function testPersist_withValidDetailsFallbackOnly_usesTheSanitizedValues(): void
    {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);

        // Computed relative to today rather than hardcoded, since sanitizeExpirationYear() rejects
        // anything before the wall-clock current year — a fixed literal would eventually become a
        // past year and start failing this test for no code-regression reason.
        $futureYear = (int) (new \DateTimeImmutable())->format('Y') + 1;

        $this->persister->persist(
            'alias_1',
            $payment,
            $this->corePaymentMethod(),
            [
                'hosted_fields_last4' => '4242',
                'hosted_fields_expiration_month' => 12,
                'hosted_fields_expiration_year' => $futureYear,
                'hosted_fields_country' => 'fr',
            ],
            [],
        );

        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame($futureYear, $card->getExpirationYear());
        // Uppercased regardless of the case the client submitted it in.
        self::assertSame('FR', $card->getCountryCode());
    }

    /**
     * @dataProvider malformedDetailsFallbackProvider
     *
     * @param mixed[] $details
     */
    public function testPersist_withMalformedDetailsFallbackValues_discardsThemAsIfAbsent(
        array $details,
        string $getter,
        string|int $defaultValue,
    ): void {
        $payment = $this->paymentWithOrder($this->createMock(CustomerInterface::class));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);

        $this->persister->persist('alias_1', $payment, $this->corePaymentMethod(), $details, []);

        self::assertSame($defaultValue, $card->$getter());
    }

    /** @return array<string, array{0: mixed[], 1: string, 2: string|int}> */
    public static function malformedDetailsFallbackProvider(): array
    {
        return [
            'brand not in the allowed list' => [['hosted_fields_selected_brand' => 'AMEX'], 'getBrand', ''],
            'last4 not 4 digits' => [['hosted_fields_last4' => '42'], 'getLast4', ''],
            'last4 not numeric' => [['hosted_fields_last4' => 'abcd'], 'getLast4', ''],
            'last4 with trailing newline' => [['hosted_fields_last4' => "4242\n"], 'getLast4', ''],
            'expiration month out of range' => [['hosted_fields_expiration_month' => 13], 'getExpirationMonth', 0],
            'expiration month zero' => [['hosted_fields_expiration_month' => 0], 'getExpirationMonth', 0],
            'expiration year in the past' => [['hosted_fields_expiration_year' => 2000], 'getExpirationYear', 0],
            'expiration year implausibly far ahead' => [['hosted_fields_expiration_year' => 9999], 'getExpirationYear', 0],
            'country not two letters' => [['hosted_fields_country' => 'FRA'], 'getCountryCode', ''],
            'country not alphabetic' => [['hosted_fields_country' => '12'], 'getCountryCode', ''],
            'country with trailing newline' => [['hosted_fields_country' => "fr\n"], 'getCountryCode', ''],
        ];
    }
}
