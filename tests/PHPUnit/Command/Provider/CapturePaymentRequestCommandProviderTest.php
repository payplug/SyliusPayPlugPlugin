<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Provider\CapturePaymentRequestCommandProvider;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class CapturePaymentRequestCommandProviderTest extends TestCase
{
    use PaymentRequestWithGatewayConfigTrait;

    private PaymentRequestCommandProviderInterface&MockObject $hostedFieldsCommandProvider;

    private CapturePaymentRequestCommandProvider $provider;

    protected function setUp(): void
    {
        $this->hostedFieldsCommandProvider = $this->createMock(PaymentRequestCommandProviderInterface::class);
        $this->provider = new CapturePaymentRequestCommandProvider($this->hostedFieldsCommandProvider);
    }

    public function testProvide_forPayplugWithHostedFieldsEnabled_delegatesToHostedFieldsProvider(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig([
            'factoryName' => PayPlugGatewayFactory::FACTORY_NAME,
            'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => true],
        ]);

        $expected = new CaptureHostedPaymentRequest('1');
        $this->hostedFieldsCommandProvider->expects(self::once())->method('provide')
            ->with($paymentRequest)->willReturn($expected);

        self::assertSame($expected, $this->provider->provide($paymentRequest));
    }

    public function testProvide_forPayplugWithoutHostedFields_usesLegacyFlow(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig([
            'factoryName' => PayPlugGatewayFactory::FACTORY_NAME,
            'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => false],
        ]);

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(CapturePaymentRequest::class, $this->provider->provide($paymentRequest));
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

        self::assertInstanceOf(CapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_whenNoGatewayConfig_usesLegacyFlow(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig(null);

        $this->hostedFieldsCommandProvider->expects(self::never())->method('provide');

        self::assertInstanceOf(CapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_whenAlreadyCreated_returnsOfflineCaptureRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithConfig(
            ['factoryName' => PayPlugGatewayFactory::FACTORY_NAME, 'config' => [PayPlugGatewayFactory::HOSTED_FIELDS => false]],
            ['status' => 'captured', 'payment_id' => 'pay_1'],
        );

        self::assertInstanceOf(OfflineCapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testSupports_onlyForCaptureAction(): void
    {
        $captureRequest = $this->createMock(PaymentRequestInterface::class);
        $captureRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_CAPTURE);
        self::assertTrue($this->provider->supports($captureRequest));

        $notifyRequest = $this->createMock(PaymentRequestInterface::class);
        $notifyRequest->method('getAction')->willReturn(PaymentRequestInterface::ACTION_NOTIFY);
        self::assertFalse($this->provider->supports($notifyRequest));
    }
}
