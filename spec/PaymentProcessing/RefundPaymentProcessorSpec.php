<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentProcessorInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Payum\Core\Model\GatewayConfigInterface;
use PhpSpec\ObjectBehavior;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RefundPaymentProcessorSpec extends ObjectBehavior
{
    public function let(
        RequestStack $requestStack,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        RepositoryInterface $refundPaymentRepository,
        RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        PayPlugApiClientFactoryInterface $apiClientFactory,
    ): void {
        $this->beConstructedWith(
            $requestStack,
            $logger,
            $translator,
            $refundPaymentRepository,
            $payplugRefundHistoryRepository,
            $apiClientFactory,
        );
    }

    public function it_is_initializable(): void
    {
        $this->shouldHaveType(RefundPaymentProcessor::class);
    }

    public function it_implements_payment_processor_interface(): void
    {
        $this->shouldHaveType(PaymentProcessorInterface::class);
    }

    public function it_processes(
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig,
    ): void {
        $gatewayConfig->getFactoryName()->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->getConfig()->willReturn([
            'secretKey' => 'test',
        ]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getMethod()->willReturn($paymentMethod);
        $payment->getDetails()->willReturn([
            'payment_id' => 'test',
        ]);
    }
}
