<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\CaptureHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface as BasePaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class CaptureHostedPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private UnifiedApiPaymentCreatorInterface&MockObject $unifiedApiPaymentCreator;

    private OperationStatusFetcherInterface&MockObject $operationStatusFetcher;

    private UrlProviderInterface&MockObject $afterPayUrlProvider;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private RequestStack&MockObject $requestStack;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private FactoryInterface&MockObject $payplugCardFactory;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private CaptureHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->unifiedApiPaymentCreator = $this->createMock(UnifiedApiPaymentCreatorInterface::class);
        $this->operationStatusFetcher = $this->createMock(OperationStatusFetcherInterface::class);
        $this->afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $this->afterPayUrlProvider->method('getUrl')->willReturn('https://shop.test/order/00000042/pay');
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->payplugCardFactory = $this->createMock(FactoryInterface::class);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);

        $this->handler = new CaptureHostedPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->unifiedApiPaymentCreator,
            $this->operationStatusFetcher,
            $this->afterPayUrlProvider,
            $this->urlGenerator,
            $this->logger,
            $this->requestStack,
            $this->orderStateMutator,
            $this->payplugCardFactory,
            $this->payplugCardRepository,
            new OrderAddressDtoFactory(),
        );
    }

    private function paymentRequestWithPayment(
        array $details,
        int $amount = 1000,
        string $currency = 'EUR',
        ?array $gatewayConfig = ['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1'],
        ?AddressInterface $billingAddress = null,
    ): PaymentRequestInterface&MockObject
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        if (null !== $gatewayConfig) {
            $config = $this->createMock(GatewayConfigInterface::class);
            $config->method('getConfig')->willReturn($gatewayConfig);
            $method->method('getGatewayConfig')->willReturn($config);
        }

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn('customer@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getBillingAddress')->willReturn($billingAddress);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn($currency);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    public function testInvoke_onDirectSuccess_completesThePaymentRequestWithoutARedirect(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, null));

        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => !isset($data['redirect_url'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new CaptureHostedPaymentRequest($paymentRequest->getId()));
    }

    public function testInvoke_whenNoHostedFieldsTokenStored_failsThePaymentRequestInsteadOfCrashing(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment([]);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => isset($data['error'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenGatewayConfigIsMissingAccountOrSubmerchantId_failsThePaymentRequestInsteadOfCrashing(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc'], gatewayConfig: null);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => isset($data['error'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onApiException_failsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')->willThrowException(new ApiException('boom'));

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenCustomerEmailIsMissing_failsThePaymentRequestInsteadOfCallingUnifiedApiPaymentCreator(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1']);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn(null);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn(['hosted_fields_token' => 'hf_token_abc']);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => isset($data['error'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithoutExecCode_neverAppliesOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, null));

        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithSuccessExecCode_appliesPaidOutcomeToOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, null));

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithFailureExecCode_appliesFailedOutcomeToOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"9999"}', null, null, null));

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::FAILED);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onRedirectOutcome_neverAppliesOrderStateMutator(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', 'https://example.com/3ds', null, null));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(['redirect_url' => 'https://example.com/3ds']);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onPending3ds_storesTheUnifiedApiPaymentAndOperationIdsOnThePaymentDetails(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);
        $payment = $paymentRequest->getPayment();

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(200, '{"id":"pay_1","execCode":"0001","operationIds":["op_1"]}', 'https://example.com/3ds', null, null));

        $payment->expects(self::once())->method('setDetails')
            ->with(self::callback(static fn (array $details): bool => 'pay_1' === $details['hosted_fields_payment_id'] &&
                'op_1' === $details['hosted_fields_operation_id']));

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenResponseBodyHasNoId_neverStoresAHostedFieldsPaymentOrOperationId(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);
        $payment = $paymentRequest->getPayment();

        $this->unifiedApiPaymentCreator->method('createPayment')->willReturn(new PaymentOutput(201, '{}', null, null, null));

        $payment->expects(self::once())->method('setDetails')
            ->with(self::callback(static fn (array $details): bool => !isset($details['hosted_fields_payment_id']) &&
                !isset($details['hosted_fields_operation_id'])));

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onRedirectHtmlOutcome_storesRedirectHtmlAndNeverAppliesOrderStateMutator(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0001"}', null, '<form>3ds</form>', null));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(['redirect_html' => '<form>3ds</form>']);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_setsSuccessAndCancelUrlOnTheUnifiedApiRequest(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (HostedFieldDto $dto): bool {
                self::assertSame('https://shop.test/order/00000042/pay', $dto->common->successUrl);
                self::assertSame('https://shop.test/order/00000042/pay?status=canceled', $dto->common->cancelUrl);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, null));

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_withABillingAddress_sendsItsFullNameAlongsideSelectedBrand(): void
    {
        $billingAddress = $this->createMock(AddressInterface::class);
        $billingAddress->method('getFullName')->willReturn('John Doe');

        $this->paymentRequestWithPayment(
            ['hosted_fields_token' => 'hf_token_abc', 'hosted_fields_selected_brand' => 'VISA'],
            billingAddress: $billingAddress,
        );

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (HostedFieldDto $dto): bool {
                self::assertIsArray($dto->paymentMethod);
                self::assertSame('John Doe', $dto->paymentMethod['details']['fullName'] ?? null);
                self::assertSame('VISA', $dto->paymentMethod['details']['selectedBrand'] ?? null);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, null));

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_withNoBillingAddress_omitsFullNameButStillSendsSelectedBrand(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc', 'hosted_fields_selected_brand' => 'VISA']);

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (HostedFieldDto $dto): bool {
                self::assertIsArray($dto->paymentMethod);
                self::assertArrayNotHasKey('fullName', $dto->paymentMethod['details'] ?? []);
                self::assertSame('VISA', $dto->paymentMethod['details']['selectedBrand'] ?? null);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, null));

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenSaveCardRequestedAndAliasReturned_persistsANewCard(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
            'hosted_fields_last4' => '4242',
            'hosted_fields_expiration_month' => 12,
            'hosted_fields_expiration_year' => 2030,
            'hosted_fields_country' => 'FR',
        ]);

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (HostedFieldDto $dto): bool {
                self::assertSame('ONE_CLICK', $dto->recurringMode);
                self::assertIsArray($dto->paymentMethod);
                self::assertTrue($dto->paymentMethod['saveFutureUsage'] ?? false);
                self::assertSame('VISA', $dto->paymentMethod['details']['selectedBrand'] ?? null);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, 'alias_new_1'));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));

        self::assertSame('alias_new_1', $card->getExternalId());
        self::assertSame('VISA', $card->getBrand());
        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2030, $card->getExpirationYear());
        self::assertSame('FR', $card->getCountryCode());
    }

    public function testInvoke_whenSaveCardRequestedAndUnifiedApiOperationIdAvailable_enrichesTheCardWithExpirationFetchedFromTheUnifiedApi(): void
    {
        $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
            'hosted_fields_country' => 'BE',
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000","operationIds":["op_1"]}', null, null, 'alias_new_1'));

        // Real shape confirmed against a staging operation response: card metadata lives under
        // paymentMethod.card (network, a masked code6x4 PAN standing in for a dedicated last4
        // field) and paymentMethod.details (selectedBrand, validityDate in "YYYY-MM" form). No
        // country field exists anywhere on that response.
        $this->operationStatusFetcher->expects(self::once())->method('getOperation')
            ->with('op_1')
            ->willReturn(['status' => 200, 'body' => json_encode([
                'paymentMethod' => [
                    'id' => 'alias_new_1',
                    'card' => [
                        'code6x4' => '424242XXXXXX4242',
                        'network' => 'VISA',
                    ],
                    'details' => [
                        'fullName' => 'John Doe',
                        'validityDate' => '2027-12',
                        'selectedBrand' => 'VISA',
                    ],
                ],
            ])]);

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));

        self::assertSame('4242', $card->getLast4());
        self::assertSame('BE', $card->getCountryCode());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2027, $card->getExpirationYear());
    }

    public function testInvoke_whenSaveCardRequestedAndFetchingTheOperationFails_stillPersistsTheCardUsingDetailsFallback(): void
    {
        $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
            'hosted_fields_last4' => '4242',
            'hosted_fields_expiration_month' => 12,
            'hosted_fields_expiration_year' => 2030,
            'hosted_fields_country' => 'FR',
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000","operationIds":["op_1"]}', null, null, 'alias_new_1'));

        $this->operationStatusFetcher->method('getOperation')->willThrowException(new ApiException('boom'));

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));

        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2030, $card->getExpirationYear());
        self::assertSame('FR', $card->getCountryCode());
    }

    /**
     * @dataProvider malformedOperationResponseBodyProvider
     */
    public function testInvoke_whenSaveCardRequestedAndOperationResponseShapeIsMalformed_stillPersistsTheCardUsingDetailsFallback(
        string $body,
    ): void {
        $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
            'hosted_fields_last4' => '4242',
            'hosted_fields_expiration_month' => 12,
            'hosted_fields_expiration_year' => 2030,
            'hosted_fields_country' => 'FR',
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000","operationIds":["op_1"]}', null, null, 'alias_new_1'));

        $this->operationStatusFetcher->method('getOperation')->willReturn(['status' => 200, 'body' => $body]);

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));

        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2030, $card->getExpirationYear());
        self::assertSame('FR', $card->getCountryCode());
    }

    /** @return array<string, array{0: string}> */
    public static function malformedOperationResponseBodyProvider(): array
    {
        return [
            'non-array body' => ['"just a string"'],
            'paymentMethod key missing' => [json_encode(['id' => 'op_1'])],
            'card key missing' => [json_encode(['paymentMethod' => ['details' => ['selectedBrand' => 'VISA']]])],
            'details key missing' => [json_encode(['paymentMethod' => ['card' => ['network' => 'VISA']]])],
            'validityDate does not match the expected YYYY-MM format' => [json_encode(['paymentMethod' => ['details' => ['validityDate' => '1225']]])],
            'code6x4 shorter than 4 characters' => [json_encode(['paymentMethod' => ['card' => ['code6x4' => '42']]])],
        ];
    }

    public function testInvoke_whenSaveCardNotRequested_neverPersistsACard(): void
    {
        $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_save_card' => false,
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, null));

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenSaveCardRequestedButNoAliasReturned_neverPersistsACard(): void
    {
        $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_save_card' => true,
        ]);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, null));

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenSaveCardRequestedButMethodIsNotCorePaymentMethod_neverPersistsACard(): void
    {
        // Card::$paymentMethod requires Sylius Core's PaymentMethodInterface, which every real
        // Sylius-wired payment method satisfies — a test double built against only the base
        // Payment component's PaymentMethodInterface exercises the guard that skips persisting a
        // card entirely rather than flushing one with that mandatory field left unset.
        $method = $this->createMock(BasePaymentMethodInterface::class);
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1']);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn('customer@example.com');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn(['hosted_fields_token' => 'hf_token_abc', 'hosted_fields_save_card' => true]);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, 'alias_new_1'));

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }
}
