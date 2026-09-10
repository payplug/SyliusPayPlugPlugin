<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Provider\NotifyPaymentRequestCommandProvider;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class NotifyPaymentRequestCommandProviderTest extends TestCase
{
    use PaymentRequestWithGatewayConfigTrait;

    private PaymentRequestCommandProviderInterface&MockObject $hostedFieldsCommandProvider;

    private NotifyPaymentRequestCommandProvider $provider;

    protected function setUp(): void
    {
        $this->hostedFieldsCommandProvider = $this->createMock(PaymentRequestCommandProviderInterface::class);
        $this->provider = new NotifyPaymentRequestCommandProvider($this->hostedFieldsCommandProvider);
    }

    public function testProvide_forPayplugWithHostedFieldsEnabled_delegatesToHostedFieldsProvider(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig([
            'factoryName' => PayPlugGatewayFactory::FACTORY_NAME,
            'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => true],
        ]);

        $expected = new NotifyHostedPaymentRequest('1');
        $this->hostedFieldsCommandProvider->expects(self::once())->method('provide')
            ->with($paymentRequest)->willReturn($expected);

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

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(NotifyPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_whenNoGatewayConfig_usesLegacyFlow(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig(null);

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(NotifyPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testSupports_onlyForNotifyAction(): void
    {
        $notifyRequest = $this->createMock(PaymentRequestInterface::class);
        $notifyRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_NOTIFY);
        self::assertTrue($this->provider->supports($notifyRequest));

        $captureRequest = $this->createMock(PaymentRequestInterface::class);
        $captureRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_CAPTURE);
        self::assertFalse($this->provider->supports($captureRequest));
    }
}
