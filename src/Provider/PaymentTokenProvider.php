<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Payum\Core\Security\TokenInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentTokenProvider
{
    public function __construct(
        private Payum $payum,
        #[Autowire('sylius_shop_order_after_pay')]
        private string $afterPayRoute,
    ) {
    }

    public function getPaymentToken(PaymentInterface $payment): TokenInterface
    {
        $tokenFactory = $this->payum->getTokenFactory();

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (
            isset($gatewayConfig->getConfig()['use_authorize']) &&
            true === $gatewayConfig->getConfig()['use_authorize']
        ) {
            return $tokenFactory->createAuthorizeToken(
                $gatewayConfig->getGatewayName(),
                $payment,
                $this->afterPayRoute,
            );
        }

        return $tokenFactory->createCaptureToken(
            $gatewayConfig->getGatewayName(),
            $payment,
            $this->afterPayRoute,
        );
    }
}
