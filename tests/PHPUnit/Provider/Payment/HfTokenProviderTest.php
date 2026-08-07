<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Provider\Payment;

use PayPlug\SyliusPayPlugPlugin\Provider\Payment\HfTokenProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class HfTokenProviderTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private SessionInterface&MockObject $session;

    private HfTokenProvider $provider;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->requestStack->method('getSession')->willReturn($this->session);

        $this->provider = new HfTokenProvider($this->requestStack);
    }

    public function testGetHfToken_whenStoredOnThePayment_returnsItWithoutTouchingTheSession(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['hf_token' => 'hf_real_token']);

        $this->session->expects(self::never())->method('get');

        self::assertSame('hf_real_token', $this->provider->getHfToken($payment));
    }

    public function testGetHfToken_whenNotOnThePayment_fallsBackToTheTestSessionToken(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);

        $this->session->method('get')->with('payplug_uhf_test_hf_token')->willReturn('hf_test_token');

        self::assertSame('hf_test_token', $this->provider->getHfToken($payment));
    }

    public function testGetHfToken_whenNeitherIsSet_returnsNull(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);

        $this->session->method('get')->willReturn(null);

        self::assertNull($this->provider->getHfToken($payment));
    }

    public function testGetHfToken_whenPaymentTokenIsAnEmptyString_fallsBackToTheTestSessionToken(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['hf_token' => '']);

        $this->session->method('get')->willReturn('hf_test_token');

        self::assertSame('hf_test_token', $this->provider->getHfToken($payment));
    }
}
