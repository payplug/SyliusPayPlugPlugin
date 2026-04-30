<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\Payment as PayplugPayment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class PaymentNotificationHandlerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private FactoryInterface&MockObject $payplugCardFactory;

    private CustomerRepositoryInterface&MockObject $customerRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private LockFactory&MockObject $lockFactory;

    private RequestStack&MockObject $requestStack;

    private PaymentNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);
        $this->payplugCardFactory = $this->createMock(FactoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->handler = new PaymentNotificationHandler(
            $this->logger,
            $this->payplugCardRepository,
            $this->payplugCardFactory,
            $this->customerRepository,
            $this->entityManager,
            $this->lockFactory,
            $this->requestStack,
        );
    }

    // -------------------------------------------------------------------------
    // treat() — guard: non-Payment resource is ignored
    // -------------------------------------------------------------------------

    /**
     * Passes a non-Payment Payplug resource (e.g. a Refund) to treat().
     * Verifies the handler returns immediately without acquiring a lock or touching state.
     */
    public function testTreat_withNonPaymentResource_doesNothing(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $resource = $this->createMock(\Payplug\Resource\IVerifiableAPIResource::class);
        $details = new \ArrayObject();

        // lock should never be created
        $this->lockFactory->expects(self::never())->method('createLock');

        $this->handler->treat($payment, $resource, $details);
    }

    // -------------------------------------------------------------------------
    // treat() — status already ABORTED → release lock, return early
    // -------------------------------------------------------------------------

    /**
     * Pre-fills details with STATUS_ABORTED before calling treat().
     * Verifies the lock is released and the status remains ABORTED (no further processing).
     */
    public function testTreat_withAbortedStatus_releasesLockAndReturns(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('release');

        $payment = $this->createMock(PaymentInterface::class);
        $this->entityManager->expects(self::once())->method('refresh');

        $paymentResource = $this->buildPayment(['id' => 'pay_abc', 'is_paid' => false, 'is_live' => false]);
        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_ABORTED]);

        $this->handler->treat($payment, $paymentResource, $details);

        // Status must remain ABORTED
        self::assertSame(PayPlugApiClientInterface::STATUS_ABORTED, $details['status']);
    }

    // -------------------------------------------------------------------------
    // treat() — is_paid = true → STATUS_CAPTURED
    // -------------------------------------------------------------------------

    /**
     * Builds a Payplug Payment resource with is_paid=true and no card metadata.
     * Verifies treat() sets the details status to STATUS_CAPTURED.
     */
    public function testTreat_withIsPaidTrue_setsStatusCaptured(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($this->createMock(PaymentMethodInterface::class));
        $this->entityManager->method('refresh');

        // is_paid=true, no metadata → saveCard is a no-op
        $paymentResource = $this->buildPayment([
            'id' => 'pay_001',
            'is_paid' => true,
            'is_live' => false,
            'created_at' => time(),
        ]);
        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::STATUS_CAPTURED, $details['status']);
    }

    // -------------------------------------------------------------------------
    // treat() — Oney pending file → STATUS_AUTHORIZED
    // -------------------------------------------------------------------------

    /**
     * Builds an Oney payment with payment_method.is_pending=true (under legal-document review).
     * Verifies treat() sets the status to STATUS_AUTHORIZED (awaiting Oney approval).
     */
    public function testTreat_withOneyPendingReview_setsStatusAuthorized(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $payment = $this->createMock(PaymentInterface::class);
        $this->entityManager->method('refresh');

        // is_paid=false, payment_method with is_pending=true
        $paymentResource = $this->buildPayment([
            'id' => 'pay_002',
            'is_paid' => false,
            'is_live' => false,
            'payment_method' => ['is_pending' => true, 'type' => 'oney_x3_with_fees'],
        ]);
        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::STATUS_AUTHORIZED, $details['status']);
    }

    // -------------------------------------------------------------------------
    // treat() — Oney refused → STATUS_CANCELED_BY_ONEY
    // -------------------------------------------------------------------------

    /**
     * Builds an Oney payment with is_pending=false and a failure code (Oney refusal).
     * Verifies status is set to STATUS_CANCELED_BY_ONEY and failure details are copied into details.
     */
    public function testTreat_withOneyRefused_setsStatusCanceledByOney(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $payment = $this->createMock(PaymentInterface::class);
        $this->entityManager->method('refresh');

        $oneyType = OneyGatewayFactory::PAYMENT_CHOICES[0]; // 'oney_x3_with_fees'

        $paymentResource = $this->buildPayment([
            'id' => 'pay_003',
            'is_paid' => false,
            'is_live' => false,
            'payment_method' => ['is_pending' => false, 'type' => $oneyType],
            'failure' => ['code' => 'card_declined', 'message' => 'Card declined'],
        ]);
        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY, $details['status']);
        self::assertSame('card_declined', $details['failure']['code']);
    }

    // -------------------------------------------------------------------------
    // treat() — generic failure → FAILED
    // -------------------------------------------------------------------------

    /**
     * Builds a non-Oney payment with a failure code and is_paid=false.
     * Verifies status is set to FAILED and the failure details (code, message) are persisted.
     */
    public function testTreat_withGenericFailure_setsStatusFailed(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $payment = $this->createMock(PaymentInterface::class);
        $this->entityManager->method('refresh');
        $this->logger->expects(self::once())->method('info');

        $paymentResource = $this->buildPayment([
            'id' => 'pay_004',
            'is_paid' => false,
            'is_live' => false,
            'failure' => ['code' => 'do_not_honor', 'message' => 'Do not honor'],
        ]);
        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::FAILED, $details['status']);
        self::assertSame('do_not_honor', $details['failure']['code']);
    }

    // -------------------------------------------------------------------------
    // treat() — card saving: card saved when metadata has valid customer_id
    // -------------------------------------------------------------------------

    /**
     * Builds a paid payment with metadata.customer_id and full card data in the response.
     * Verifies a new Card entity is created via the factory and persisted via the repository.
     */
    public function testTreat_withIsPaidAndValidCardMetadata_savesCard(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $customer = $this->createMock(CustomerInterface::class);
        $this->customerRepository->method('find')->with(5)->willReturn($customer);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $this->entityManager->method('refresh');

        $paymentResource = $this->buildPayment([
            'id' => 'pay_005',
            'is_paid' => true,
            'is_live' => true,
            'created_at' => time(),
            'metadata' => ['customer_id' => 5],
            'card' => [
                'id' => 'card_external_123',
                'brand' => 'Visa',
                'country' => 'FR',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
            ],
        ]);

        // No existing card in repo
        $this->payplugCardRepository->method('findOneBy')->willReturn(null);

        $card = $this->createMock(Card::class);
        $card->method('setCustomer')->willReturnSelf();
        $card->method('setPaymentMethod')->willReturnSelf();
        $card->method('setExternalId')->willReturnSelf();
        $card->method('setBrand')->willReturnSelf();
        $card->method('setCountryCode')->willReturnSelf();
        $card->method('setLast4')->willReturnSelf();
        $card->method('setExpirationMonth')->willReturnSelf();
        $card->method('setExpirationYear')->willReturnSelf();
        $card->method('setIsLive')->willReturnSelf();

        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::STATUS_CAPTURED, $details['status']);
    }

    // -------------------------------------------------------------------------
    // treat() — card NOT saved when no customer_id in metadata
    // -------------------------------------------------------------------------

    /**
     * Builds a paid payment with no metadata key at all (guest checkout or feature disabled).
     * Verifies the card factory and repository are never called (no card saved).
     */
    public function testTreat_withIsPaidAndMissingCustomerId_doesNotSaveCard(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($this->createMock(PaymentMethodInterface::class));
        $this->entityManager->method('refresh');

        // is_paid=true, no metadata key at all
        $paymentResource = $this->buildPayment([
            'id' => 'pay_006',
            'is_paid' => true,
            'is_live' => false,
            'created_at' => time(),
        ]);

        $this->payplugCardRepository->expects(self::never())->method('add');
        $this->payplugCardFactory->expects(self::never())->method('createNew');

        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);
        $this->handler->treat($payment, $paymentResource, $details);

        self::assertSame(PayPlugApiClientInterface::STATUS_CAPTURED, $details['status']);
    }

    // -------------------------------------------------------------------------
    // treat() — card NOT saved when card already exists in repo
    // -------------------------------------------------------------------------

    /**
     * Builds a paid payment with card data, but the repository already returns an existing Card.
     * Verifies the card factory and repository add() are never called (no duplicate).
     */
    public function testTreat_withIsPaidAndCardAlreadyExists_doesNotSaveCardAgain(): void
    {
        $lock = $this->buildLock();
        $this->lockFactory->method('createLock')->willReturn($lock);

        $customer = $this->createMock(CustomerInterface::class);
        $this->customerRepository->method('find')->with(7)->willReturn($customer);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($this->createMock(PaymentMethodInterface::class));
        $this->entityManager->method('refresh');

        $paymentResource = $this->buildPayment([
            'id' => 'pay_007',
            'is_paid' => true,
            'is_live' => false,
            'created_at' => time(),
            'metadata' => ['customer_id' => 7],
            'card' => ['id' => 'card_ext_already', 'brand' => 'Visa', 'country' => 'FR', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2029],
        ]);

        // Card already in repo
        $existingCard = $this->createMock(Card::class);
        $this->payplugCardRepository->method('findOneBy')->willReturn($existingCard);

        $this->payplugCardFactory->expects(self::never())->method('createNew');
        $this->payplugCardRepository->expects(self::never())->method('add');

        $details = new \ArrayObject(['status' => PayPlugApiClientInterface::STATUS_CREATED]);
        $this->handler->treat($payment, $paymentResource, $details);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildLock(): SharedLockInterface&MockObject
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        return $lock;
    }

    /**
     * Creates a real Payplug Payment resource from an attributes array.
     * This uses the SDK's own factory and avoids the complexity of mocking magic methods.
     */
    private function buildPayment(array $attributes): PayplugPayment
    {
        return PayplugPayment::fromAttributes($attributes);
    }
}
