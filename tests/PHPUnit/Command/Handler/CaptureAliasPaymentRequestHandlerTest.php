<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\CaptureAliasPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Resolver\SelectedCardResolver;
use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoCreator;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureContextBuilder;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureOutcomeApplier;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Dto\PaymentDto;
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
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class CaptureAliasPaymentRequestHandlerTest extends TestCase
{
    private const SELECTED_CARD_ID = 17;

    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private UnifiedApiPaymentCreatorInterface&MockObject $unifiedApiPaymentCreator;

    private RequestStack $requestStack;

    private SessionInterface&MockObject $session;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private LoggerInterface&MockObject $logger;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private UrlProviderInterface&MockObject $afterPayUrlProvider;

    private CaptureAliasPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->unifiedApiPaymentCreator = $this->createMock(UnifiedApiPaymentCreatorInterface::class);
        $this->session = $this->createMock(SessionInterface::class);
        $sessionData = [];
        $this->session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$sessionData): void {
            $sessionData[$key] = $value;
        });
        $this->session->method('get')->willReturnCallback(static function (string $key, mixed $default = null) use (&$sessionData): mixed {
            return $sessionData[$key] ?? $default;
        });

        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($this->session);

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $this->afterPayUrlProvider->method('getUrl')->willReturn('https://shop.test/order/00000042/pay');

        $this->handler = new CaptureAliasPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->unifiedApiPaymentCreator,
            new SelectedCardResolver($this->requestStack, $this->payplugCardRepository),
            new PaymentCaptureContextBuilder($this->urlGenerator, $this->afterPayUrlProvider, new OrderAddressDtoCreator(), $this->requestStack),
            new PaymentCaptureOutcomeApplier($this->logger, $this->stateMachine, $this->orderStateMutator),
        );
    }

    /**
     * @param CustomerInterface&MockObject|null $cardCustomer customer the selected Card belongs
     *                                                         to; defaults to the paying order's
     *                                                         own customer (the happy path) —
     *                                                         pass a different mock to exercise
     *                                                         the ownership-mismatch guard
     * @param PaymentMethodInterface&MockObject|null $cardPaymentMethod payment method the selected
     *                                                         Card was saved under; defaults to
     *                                                         the payment's own method (the happy
     *                                                         path) — pass a different mock to
     *                                                         exercise the account-mismatch guard
     */
    private function paymentRequestWithSelectedCard(
        ?Card $card,
        ?array $gatewayConfig = ['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1'],
        ?CustomerInterface $cardCustomer = null,
        ?AddressInterface $billingAddress = null,
        ?PaymentMethodInterface $cardPaymentMethod = null,
        ?string $customerEmail = 'customer@example.com',
    ): PaymentRequestInterface&MockObject
    {
        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);

        $method = $this->createMock(PaymentMethodInterface::class);
        if (null !== $gatewayConfig) {
            $config = $this->createMock(GatewayConfigInterface::class);
            $config->method('getConfig')->willReturn($gatewayConfig);
            $method->method('getGatewayConfig')->willReturn($config);
        }

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn($customerEmail);

        $card?->setCustomer($cardCustomer ?? $customer);
        $card?->setPaymentMethod($cardPaymentMethod ?? $method);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn($card);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('00000042');
        $order->method('getBillingAddress')->willReturn($billingAddress);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn([]);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    private function savedCard(): Card
    {
        return (new Card())->setExternalId('alias_existing_1')->setBrand('VISA')->setLast4('4242')
            ->setExpirationMonth(12)->setExpirationYear(2030)->setCountryCode('FR')->setIsLive(false);
    }

    public function testInvoke_withNoCardSelected_failsThePaymentRequestInsteadOfCrashing(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard(null);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withGatewayConfigMissingAccountId_failsThePaymentRequestInsteadOfCrashing(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard(), gatewayConfig: null);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccess_completesThePaymentRequestWithoutARedirect(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->method('createPayment')->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, 'alias_existing_1'));

        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => !isset($data['redirect_url'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onApiException_failsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->method('createPayment')->willThrowException(new ApiException('boom'));

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withCardBelongingToAnotherCustomer_failsThePaymentRequest(): void
    {
        $anotherCustomer = $this->createMock(CustomerInterface::class);
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard(), cardCustomer: $anotherCustomer);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withCardBelongingToAnotherPaymentMethod_failsThePaymentRequest(): void
    {
        $anotherMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard(), cardPaymentMethod: $anotherMethod);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withNoCustomerEmail_failsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard(), customerEmail: null);

        $this->unifiedApiPaymentCreator->expects(self::never())->method('createPayment');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithSuccessExecCode_appliesPaidOutcomeToOrderStateMutator(): void
    {
        $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, 'alias_existing_1'));

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onRedirectOutcome_neverAppliesOrderStateMutator(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', 'https://example.com/3ds', null, 'alias_existing_1'));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(['redirect_url' => 'https://example.com/3ds']);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onRedirectHtmlOutcome_storesRedirectHtmlAndNeverAppliesOrderStateMutator(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0001"}', null, '<form>3ds</form>', 'alias_existing_1'));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(['redirect_html' => '<form>3ds</form>']);

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_setsSuccessAndCancelUrlOnTheUnifiedApiRequest(): void
    {
        $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (PaymentDto $dto): bool {
                self::assertSame('https://shop.test/order/00000042/pay', $dto->common->successUrl);
                self::assertSame('https://shop.test/order/00000042/pay?status=canceled', $dto->common->cancelUrl);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, 'alias_existing_1'));

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withABillingAddress_sendsItsFullNameAsThePaymentMethodDetails(): void
    {
        $billingAddress = $this->createMock(AddressInterface::class);
        $billingAddress->method('getFullName')->willReturn('John Doe');

        $this->paymentRequestWithSelectedCard($this->savedCard(), billingAddress: $billingAddress);

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (PaymentDto $dto): bool {
                self::assertIsArray($dto->paymentMethod);
                self::assertSame('John Doe', $dto->paymentMethod['details']['fullName'] ?? null);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, 'alias_existing_1'));

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_withNoBillingAddress_leavesPaymentMethodNull(): void
    {
        $this->paymentRequestWithSelectedCard($this->savedCard());

        $this->unifiedApiPaymentCreator->expects(self::once())->method('createPayment')
            ->with(self::callback(function (PaymentDto $dto): bool {
                self::assertNull($dto->paymentMethod);

                return true;
            }))
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1"}', null, null, 'alias_existing_1'));

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onSuccess_storesTheUnifiedApiPaymentAndOperationIdsOnThePaymentDetails(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());
        $payment = $paymentRequest->getPayment();

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(201, '{"id":"pay_1","execCode":"0000","operationIds":["op_1"]}', null, null, 'alias_existing_1'));

        $payment->expects(self::once())->method('setDetails')
            ->with(self::callback(static fn (array $details): bool => 'pay_1' === ($details['hosted_fields_payment_id'] ?? null) &&
                'op_1' === ($details['hosted_fields_operation_id'] ?? null) &&
                'alias_existing_1' === ($details['alias_id'] ?? null)));

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }

    public function testInvoke_onPending3ds_storesTheUnifiedApiPaymentAndOperationIdsOnThePaymentDetails(): void
    {
        $paymentRequest = $this->paymentRequestWithSelectedCard($this->savedCard());
        $payment = $paymentRequest->getPayment();

        $this->unifiedApiPaymentCreator->method('createPayment')
            ->willReturn(new PaymentOutput(200, '{"id":"pay_1","execCode":"0001","operationIds":["op_1"]}', 'https://example.com/3ds', null, 'alias_existing_1'));

        $payment->expects(self::once())->method('setDetails')
            ->with(self::callback(static fn (array $details): bool => 'pay_1' === ($details['hosted_fields_payment_id'] ?? null) &&
                'op_1' === ($details['hosted_fields_operation_id'] ?? null)));

        $this->handler->__invoke(new CaptureAliasPaymentRequest(null));
    }
}
