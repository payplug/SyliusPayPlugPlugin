<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Resolver;

use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Resolver\SelectedCardResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SelectedCardResolverTest extends TestCase
{
    private const SELECTED_CARD_ID = 17;

    private RequestStack $requestStack;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private SelectedCardResolver $resolver;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);

        $this->resolver = new SelectedCardResolver($this->requestStack, $this->payplugCardRepository);
    }

    public function testResolve_withNoCardIdInSession_returnsNull(): void
    {
        self::assertNull($this->resolver->resolve());
    }

    public function testResolve_withOtherCardSentinelSelected_returnsNull(): void
    {
        $this->requestStack->getSession()->set('payplug_payment_method', 'other');

        self::assertNull($this->resolver->resolve());
    }

    public function testResolve_withSelectedCardIdFound_returnsTheCard(): void
    {
        $card = new Card();
        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn($card);

        self::assertSame($card, $this->resolver->resolve());
    }

    public function testResolve_withSelectedCardIdNoLongerFound_returnsNull(): void
    {
        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn(null);

        self::assertNull($this->resolver->resolve());
    }
}
