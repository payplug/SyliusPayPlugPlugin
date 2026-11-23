<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Twig;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetEnvironment;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetResolver;
use PayPlug\SyliusPayPlugPlugin\Twig\ScalapayWidgetRuntime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ScalapayWidgetRuntimeTest extends TestCase
{
    private PaymentMethodRepositoryInterface&MockObject $paymentMethodRepository;

    private RequestStack $requestStack;

    private ScalapayWidgetRuntime $runtime;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $euro = new Currency();
        $euro->setCode('EUR');
        $channel = new Channel();
        $channel->setBaseCurrency($euro);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);
        $currencyContext = $this->createMock(CurrencyContextInterface::class);
        $currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->requestStack = new RequestStack();
        $this->runtime = new ScalapayWidgetRuntime(
            new ScalapayWidgetResolver(
                $this->paymentMethodRepository,
                $channelContext,
                $currencyContext,
                $this->createMock(LocaleContextInterface::class),
                $this->createMock(CartContextInterface::class),
                $this->createMock(ProductVariantPricesCalculatorInterface::class),
                new PproEligibilityChecker(new NullLogger(), new MockClock()),
                new ScalapayWidgetEnvironment('https://api.payplug.com'),
            ),
            $this->requestStack,
        );
    }

    public function testScriptIsLookedUpForTheRouteOfTheMainRequest(): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => 'sylius_shop_cart_summary']));
        $this->requestStack->push(new Request(attributes: ['_route' => '_live_component']));

        $this->paymentMethodRepository->expects(self::once())->method('findOneEnabledByGatewayNameAndChannel')
            ->with(ScalapayGatewayFactory::FACTORY_NAME)
            ->willReturn(null);

        self::assertFalse($this->runtime->isScriptRequired());
    }

    public function testNoScriptOutsideARequest(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('findOneEnabledByGatewayNameAndChannel');

        self::assertFalse($this->runtime->isScriptRequired());
    }

    public function testNoScriptOnAnUnrelatedRoute(): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => 'sylius_shop_homepage']));
        $this->paymentMethodRepository->expects(self::never())->method('findOneEnabledByGatewayNameAndChannel');

        self::assertFalse($this->runtime->isScriptRequired());
    }
}
