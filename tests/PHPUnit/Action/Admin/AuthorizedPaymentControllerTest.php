<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action\Admin;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Action\Admin\AuthorizedPaymentController;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AuthorizedPaymentOperationProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationOperatorInterface;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Output\CaptureOutput;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Drives a real AuthorizedPaymentOperationProcessor with only its UPC port mocked, so these
 * tests pin what a merchant's click actually leads to — money moved or not, and the message
 * shown — rather than which processor method got called.
 */
final class AuthorizedPaymentControllerTest extends TestCase
{
    private const VALID_TOKEN = 'a-valid-csrf-token';

    private AuthorizationOperatorInterface&MockObject $operator;

    private PaymentRepositoryInterface&MockObject $paymentRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private AuthorizationCheckerInterface&MockObject $authorizationChecker;

    private AuthorizedPaymentController $controller;

    protected function setUp(): void
    {
        $this->operator = $this->createMock(AuthorizationOperatorInterface::class);
        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->with('ROLE_ADMINISTRATION_ACCESS')->willReturn(true);

        $lock = $this->createMock(ILock::class);
        $lock->method('acquire')->willReturn(true);
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/admin/' . $route . '/' . ($parameters['id'] ?? ''),
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => 'payplug_authorization_42' === $token->getId() && self::VALID_TOKEN === $token->getValue(),
        );

        $this->controller = new AuthorizedPaymentController(
            $this->paymentRepository,
            new AuthorizedPaymentOperationProcessor(
                $this->operator,
                $lock,
                $stateMachine,
                new MockClock('2026-09-24T10:00:00+00:00'),
                $this->createMock(LoggerInterface::class),
            ),
            $this->entityManager,
            $router,
            $this->authorizationChecker,
            $csrfTokenManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function amounts(): iterable
    {
        yield 'blank means the whole remainder' => ['', null];
        yield 'integer' => ['12', 1200];
        yield 'dot decimal' => ['12.5', 1250];
        yield 'comma decimal' => ['12,50', 1250];
        yield 'thousands space' => ['1 234,56', 123456];
        yield 'too many decimals is refused downstream' => ['12.345', 0];
        yield 'negative is refused downstream' => ['-5', 0];
        yield 'garbage is refused downstream' => ['abc', 0];
    }

    /**
     * @dataProvider amounts
     */
    public function testParseAmount(string $raw, ?int $expected): void
    {
        self::assertSame($expected, AuthorizedPaymentController::parseAmount($raw));
    }

    public function testCapture_capturesTheTypedAmountFlushesAndRedirectsToTheOrder(): void
    {
        $payment = $this->authorizedPayment();
        $this->paymentRepository->method('find')->with(42)->willReturn($payment);
        $this->operator->expects(self::once())->method('capture')
            ->with(self::anything(), 'pay_1', self::anything(), 250)
            ->willReturn(new CaptureOutput(200, '{"operationIds":["op_c1"]}', null, null, null));
        $this->entityManager->expects(self::once())->method('flush');

        $request = $this->request(['amount' => '2,50', 'version' => '0']);
        $response = $this->controller->capture($request, 7, 42);

        self::assertSame('/admin/sylius_admin_order_show/7', $response->headers->get('Location'));
        self::assertSame(['payplug_sylius_payplug_plugin.admin.authorization.capture_success'], $this->flashes($request, 'success'));
        self::assertSame(250, AuthorizationDetails::fromDetails($payment->getDetails())->capturedAmount());
    }

    public function testCapture_refusedByUpc_showsTheReasonAndFlushesNothing(): void
    {
        $this->paymentRepository->method('find')->willReturn($this->authorizedPayment());
        $this->operator->method('capture')->willThrowException(new AuthorizationExpiredException('expired'));
        $this->entityManager->expects(self::never())->method('flush');

        $request = $this->request(['amount' => '']);
        $this->controller->capture($request, 7, 42);

        self::assertSame(['payplug_sylius_payplug_plugin.admin.authorization.error.expired'], $this->flashes($request, 'error'));
    }

    public function testCapture_aboveTheRemainder_showsTheRemainingAmount(): void
    {
        $this->paymentRepository->method('find')->willReturn($this->authorizedPayment());

        $request = $this->request(['amount' => '50']);
        $this->controller->capture($request, 7, 42);

        self::assertSame([[
            'message' => 'payplug_sylius_payplug_plugin.admin.authorization.error.amount_exceeds_remaining',
            'parameters' => ['%remaining%' => '10.00 EUR'],
        ]], $this->flashes($request, 'error'));
    }

    public function testCapture_withAnInvalidCsrfToken_movesNoMoney(): void
    {
        $this->operator->expects(self::never())->method('capture');

        $this->expectException(BadRequestHttpException::class);

        $this->controller->capture($this->request(['amount' => ''], 'forged'), 7, 42);
    }

    public function testCapture_withoutAdminRole_movesNoMoney(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(false);
        $controller = new AuthorizedPaymentController(
            $this->paymentRepository,
            new AuthorizedPaymentOperationProcessor($this->operator, $this->createMock(ILock::class), $this->createMock(StateMachineInterface::class), new MockClock(), $this->createMock(LoggerInterface::class)),
            $this->entityManager,
            $this->createMock(RouterInterface::class),
            $authorizationChecker,
            null,
            $this->createMock(LoggerInterface::class),
        );
        $this->operator->expects(self::never())->method('capture');

        $this->expectException(AccessDeniedHttpException::class);

        $controller->capture($this->request(['amount' => '']), 7, 42);
    }

    public function testCapture_ofAPaymentFromAnotherOrder_is404(): void
    {
        $this->paymentRepository->method('find')->willReturn($this->authorizedPayment());
        $this->operator->expects(self::never())->method('capture');

        $this->expectException(NotFoundHttpException::class);

        $this->controller->capture($this->request(['amount' => '']), 8, 42);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(array $body, string $csrfToken = self::VALID_TOKEN): Request
    {
        $request = new Request([], [...$body, '_csrf_token' => $csrfToken]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return mixed[]
     */
    private function flashes(Request $request, string $type): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->get($type);
    }

    private function authorizedPayment(): Payment
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setConfig([PayPlugGatewayFactory::HOSTED_FIELDS => true, PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_1']);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $order = new class() extends Order {
            public function getId(): int
            {
                return 7;
            }
        };

        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setOrder($order);
        $payment->setMethod($method);
        $payment->setAmount(1000);
        $payment->setCurrencyCode('EUR');
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);
        $payment->setDetails(AuthorizationDetails::open(
            ['hosted_fields_payment_id' => 'pay_1'],
            new PaymentOutput(201, '{}', null, null, null, '2026-09-30T10:00:00+00:00', 1000),
            1000,
        ));

        return $payment;
    }
}
