<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepository;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository\Fixture\QueryOrder;
use Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository\Fixture\QueryPayment;

/**
 * Runs the repository's real queries on an in-memory SQLite database, against fixture entities
 * mapping only the columns those queries read.
 *
 * @requires extension pdo_sqlite
 */
final class PaymentRepositoryTest extends TestCase
{
    private const CREATED_AT = '2026-10-08T10:00:00+00:00';

    private EntityManager $entityManager;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixture'], true, sys_get_temp_dir());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $metadata = [
            $this->entityManager->getClassMetadata(QueryOrder::class),
            $this->entityManager->getClassMetadata(QueryPayment::class),
        ];
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->repository = new PaymentRepository($this->entityManager, $metadata[1]);
    }

    public function testFindAwaitingHostedFieldsPaymentId_withOneMatchingPayment_returnsIt(): void
    {
        $payment = $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, ['hosted_fields_created_at' => self::CREATED_AT]);

        self::assertSame([$payment->getId()], $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_withASavedCardPayment_returnsIt(): void
    {
        $payment = $this->payment('000000042', 1000, PaymentInterface::STATE_PROCESSING, ['alias_payment_created_at' => self::CREATED_AT]);

        self::assertSame([$payment->getId()], $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_withSeveralMatchingPayments_returnsTwoOfThem(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, ['hosted_fields_created_at' => self::CREATED_AT]);
        }

        self::assertCount(2, $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_whenAPaymentIdIsAlreadyStored_excludesThePayment(): void
    {
        $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, [
            'hosted_fields_created_at' => self::CREATED_AT,
            'hosted_fields_payment_id' => 'pay_1',
        ]);

        self::assertSame([], $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_whenCreationHasNotCommitted_excludesThePayment(): void
    {
        $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, ['hosted_fields_token' => 'hf_token_abc']);

        self::assertSame([], $this->findIds('000000042', 1000));
    }

    /** @dataProvider inactiveStates */
    public function testFindAwaitingHostedFieldsPaymentId_withAPaymentNoLongerActive_excludesIt(string $state): void
    {
        $this->payment('000000042', 1000, $state, ['hosted_fields_created_at' => self::CREATED_AT]);

        self::assertSame([], $this->findIds('000000042', 1000));
    }

    /** @return iterable<string, array{0: string}> */
    public static function inactiveStates(): iterable
    {
        yield 'failed' => [PaymentInterface::STATE_FAILED];
        yield 'cancelled' => [PaymentInterface::STATE_CANCELLED];
        yield 'completed' => [PaymentInterface::STATE_COMPLETED];
        yield 'authorized' => [PaymentInterface::STATE_AUTHORIZED];
        yield 'refunded' => [PaymentInterface::STATE_REFUNDED];
    }

    public function testFindAwaitingHostedFieldsPaymentId_withAFailedSiblingPayment_returnsOnlyTheActiveOne(): void
    {
        $this->payment('000000042', 1000, PaymentInterface::STATE_FAILED, ['hosted_fields_created_at' => self::CREATED_AT]);
        $active = $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, ['hosted_fields_created_at' => self::CREATED_AT]);

        self::assertSame([$active->getId()], $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_withAnotherOrderNumberOrAmount_excludesThePayment(): void
    {
        $this->payment('000000043', 1000, PaymentInterface::STATE_NEW, ['hosted_fields_created_at' => self::CREATED_AT]);
        $this->payment('000000042', 999, PaymentInterface::STATE_NEW, ['hosted_fields_created_at' => self::CREATED_AT]);

        self::assertSame([], $this->findIds('000000042', 1000));
    }

    public function testFindAwaitingHostedFieldsPaymentId_treatsUnderscoresInTheKeysLiterally(): void
    {
        $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, ['hostedXfieldsXcreatedXat' => self::CREATED_AT]);
        $lookalikePaymentIdKey = $this->payment('000000042', 1000, PaymentInterface::STATE_NEW, [
            'hosted_fields_created_at' => self::CREATED_AT,
            'hostedXfieldsXpaymentXid' => 'pay_1',
        ]);

        self::assertSame([$lookalikePaymentIdKey->getId()], $this->findIds('000000042', 1000));
    }

    /** @param mixed[] $details */
    private function payment(string $orderNumber, int $amount, string $state, array $details): QueryPayment
    {
        $order = new QueryOrder($orderNumber);
        $payment = new QueryPayment($order, $amount, $state, $details);
        $this->entityManager->persist($order);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    /** @return array<int|null> */
    private function findIds(string $orderNumber, int $amount): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (object $payment): ?int => $payment instanceof QueryPayment ? $payment->getId() : null,
            $this->repository->findAwaitingHostedFieldsPaymentId($orderNumber, $amount),
        );
    }
}
