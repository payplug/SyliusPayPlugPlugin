<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Spike;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ConfigurationRepository\PayplugConfigurationRepository;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PayplugOrderStateMutator;
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusPaymentRepository;
use PayPlug\SyliusPayPlugPlugin\TokenCache\PayplugTokenCache;
use PayplugUnifiedCore\Models\OperationData;
use PayplugUnifiedCore\Models\PaymentOutcome;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\PayPlug\SyliusPayPlugPlugin\Entity\Payment;

/**
 * PRE-3469 spike, level 3: exercises the 4 skeleton classes against a *real* booted Sylius
 * kernel — real Doctrine EntityManager, real Symfony Workflow state machine, real PSR-6 cache
 * pool, real fixture data — no mocks. Excluded from the default `phpunit.xml.dist` run (see
 * there for why); run it explicitly once you have a reachable database with the standard
 * test-application schema + fixtures loaded (`doctrine:database:create`,
 * `doctrine:migration:migrate` — this also creates `payplug_operation`, see migrations/ —
 * `sylius:fixtures:load`, all `--env=test`), then:
 *   `vendor/bin/phpunit tests/PHPUnit/Spike/SpikeIntegrationTest.php`
 * Override DATABASE_URL via `tests/TestApplication/.env.test.local` (gitignored) if your
 * local/system MySQL doesn't accept passwordless root on 127.0.0.1, e.g. against a throwaway
 * `docker run -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mariadb` container.
 */
final class SpikeIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testOrderStateMutator_realStateMachine_transitionsPaymentToCompleted(): void
    {
        $order = $this->createOrderWithFreshPayment();
        $payment = $order->getLastPayment();
        self::assertNotNull($payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());

        $mutator = new PayplugOrderStateMutator(
            self::getContainer()->get('sylius.repository.order'),
            self::getContainer()->get(StateMachineInterface::class),
            $this->entityManager,
        );

        $mutator->apply((string) $order->getId(), PaymentOutcome::PAID);

        $this->entityManager->refresh($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function testOrderStateMutator_threeDsPending_leavesRealPaymentUntouched(): void
    {
        $order = $this->createOrderWithFreshPayment();
        $payment = $order->getLastPayment();
        self::assertNotNull($payment);

        $mutator = new PayplugOrderStateMutator(
            self::getContainer()->get('sylius.repository.order'),
            self::getContainer()->get(StateMachineInterface::class),
            $this->entityManager,
        );

        $mutator->apply((string) $order->getId(), PaymentOutcome::THREE_DS_PENDING);

        $this->entityManager->refresh($payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function testTokenCache_realCachePool_roundTripsThroughRealAdapter(): void
    {
        $cachePool = self::getContainer()->get(CacheItemPoolInterface::class);
        $tokenCache = new PayplugTokenCache($cachePool);

        $tokenCache->set('pre3469-spike-token', 'jwt-value', 300);
        self::assertSame('jwt-value', $tokenCache->get('pre3469-spike-token'));

        $tokenCache->delete('pre3469-spike-token');
        self::assertNull($tokenCache->get('pre3469-spike-token'));
    }

    public function testConfigurationRepository_realGatewayConfig_survivesADoctrineRoundTrip(): void
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('payplug_pre3469_spike');
        $gatewayConfig->setGatewayName('PRE-3469 spike');
        $gatewayConfig->setConfig(['live' => false, 'test_client' => ['client_id' => 'spike-client-id']]);
        $this->entityManager->persist($gatewayConfig);
        $this->entityManager->flush();
        $id = $gatewayConfig->getId();
        $this->entityManager->clear();

        /** @var GatewayConfig $reloaded */
        $reloaded = $this->entityManager->find(GatewayConfig::class, $id);
        (new PayplugConfigurationRepository($reloaded))->set('client_secret', 'spike-secret-value');
        $this->entityManager->flush();
        $this->entityManager->clear();

        /** @var GatewayConfig $reloadedAgain */
        $reloadedAgain = $this->entityManager->find(GatewayConfig::class, $id);
        $repository = new PayplugConfigurationRepository($reloadedAgain);

        self::assertSame('spike-client-id', $repository->getClientId());
        self::assertSame('spike-secret-value', $repository->getClientSecret());
    }

    public function testPaymentRepository_realDoctrinePersistence_roundTripsAndTracksIdempotency(): void
    {
        $repository = new SyliusPaymentRepository($this->entityManager);
        $operationId = 'spike-op-' . \bin2hex(\random_bytes(6));
        $orderId = 'spike-order-' . \bin2hex(\random_bytes(6));
        $operationData = new OperationData($operationId, '4001', PaymentOutcome::PAID, 1500, $orderId);

        $repository->save($operationData);
        $this->entityManager->clear();

        self::assertFalse($repository->isTreated($operationId));

        $fetched = $repository->getByOperationId($operationId);
        self::assertSame($orderId, $fetched->orderId);
        self::assertSame(1500, $fetched->amount);

        $repository->markTreated($operationId);
        $this->entityManager->clear();

        self::assertTrue($repository->isTreated($operationId));
    }

    /**
     * Self-contained on purpose: earlier drafts hunted for a fixture payment already sitting in
     * state `new`, but repeated test runs against the same disposable database exhaust that
     * finite pool (fixtures don't reload between runs) — a real payment always transitions
     * *away* from `new`, so the pool only shrinks. Attaching a fresh Payment to an existing
     * fixture Order sidesteps that without needing a full from-scratch Order (channel, customer,
     * currency, locale, number, token — all fixture-provided here for free).
     */
    private function createOrderWithFreshPayment(): OrderInterface
    {
        $order = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        self::assertNotNull($order, 'Fixtures must include at least one order.');

        $method = $order->getLastPayment()?->getMethod()
            ?? $this->entityManager->getRepository(PaymentMethod::class)->createQueryBuilder('m')->setMaxResults(1)->getQuery()->getOneOrNullResult()
        ;
        self::assertNotNull($method, 'Fixtures must include at least one payment method.');

        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        $payment->setCurrencyCode($order->getCurrencyCode() ?? 'EUR');
        $payment->setAmount($order->getTotal());
        $payment->setMethod($method);
        $order->addPayment($payment);

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $order;
    }
}
