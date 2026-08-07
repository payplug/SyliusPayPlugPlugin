<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Controller;

use PayPlug\SyliusPayPlugPlugin\Controller\SetUhfTestHfTokenAction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class SetUhfTestHfTokenActionTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private SessionInterface&MockObject $session;

    private SetUhfTestHfTokenAction $action;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->requestStack->method('getSession')->willReturn($this->session);

        $this->action = new SetUhfTestHfTokenAction($this->requestStack);
    }

    public function testInvoke_withHfTokenQueryParameter_storesItInTheSession(): void
    {
        $this->session->expects(self::once())->method('set')->with('payplug_uhf_test_hf_token', 'hf_test_123');

        $response = ($this->action)(new Request(['hfToken' => 'hf_test_123']));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvoke_withoutHfTokenQueryParameter_throwsBadRequest(): void
    {
        $this->session->expects(self::never())->method('set');

        $this->expectException(BadRequestHttpException::class);

        ($this->action)(new Request());
    }
}
