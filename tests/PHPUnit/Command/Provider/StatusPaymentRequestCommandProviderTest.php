<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\Provider\StatusPaymentRequestCommandProvider;
use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\StatusPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class StatusPaymentRequestCommandProviderTest extends TestCase
{
    use PaymentRequestWithGatewayConfigTrait;

    private RequestStack&MockObject $requestStack;

    private PaymentRequestCommandProviderInterface&MockObject $hostedFieldsCommandProvider;

    private StatusPaymentRequestCommandProvider $provider;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->hostedFieldsCommandProvider = $this->createMock(PaymentRequestCommandProviderInterface::class);
        $this->provider = new StatusPaymentRequestCommandProvider($this->requestStack, $this->hostedFieldsCommandProvider);
    }

    public function testProvide_forPayplugWithHostedFieldsEnabled_delegatesToHostedFieldsProvider(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig([
            'factoryName' => PayPlugGatewayFactory::FACTORY_NAME,
            'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => true],
        ]);

        $expected = new StatusHostedPaymentRequest('1');
        $this->hostedFieldsCommandProvider->expects(self::once())->method('provide')
            ->with($paymentRequest)->willReturn($expected);
        $this->requestStack->expects(self::never())->method('getCurrentRequest');

        self::assertSame($expected, $this->provider->provide($paymentRequest));
    }

    /**
     * Other gateways (Oney, Bancontact, ...) never satisfy the Hosted Fields check regardless of
     * their own config shape, since it's gated on the `payplug` factory name first — behavior for
     * them must stay exactly as it was before this delegation was introduced.
     */
    public function testProvide_forOtherGatewayFactory_neverDelegatesEvenIfConfigHappensToHaveHostedFieldsKey(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig([
            'factoryName' => 'payplug_oney',
            'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => true],
        ]);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(StatusPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_whenNoGatewayConfig_usesLegacyFlow(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig(null);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(StatusPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testSupports_onlyForStatusAction(): void
    {
        $statusRequest = $this->createMock(PaymentRequestInterface::class);
        $statusRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_STATUS);
        self::assertTrue($this->provider->supports($statusRequest));

        $captureRequest = $this->createMock(PaymentRequestInterface::class);
        $captureRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_CAPTURE);
        self::assertFalse($this->provider->supports($captureRequest));
    }
}
