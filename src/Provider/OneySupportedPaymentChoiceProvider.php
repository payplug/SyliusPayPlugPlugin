<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

class OneySupportedPaymentChoiceProvider
{
    private PaymentMethodRepositoryInterface $paymentMethodRepository;

    public function __construct(PaymentMethodRepositoryInterface $paymentMethodRepository)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getSupportedPaymentChoices(bool $useOneyPrefix = false): array
    {
        try{
            $paymentMethod = $this->paymentMethodRepository->findOneByGatewayName(OneyGatewayFactory::FACTORY_NAME);

            if (!$paymentMethod instanceof PaymentMethodInterface) {
                return [];
            }

            /** @var GatewayConfigInterface $gateway */
            $gateway = $paymentMethod->getGatewayConfig();

            $config = $gateway->getConfig();

            $values = OneyGatewayFactory::PAYMENT_CHOICES_FEES_FOR[$config['fees_for'] ?? OneyGatewayFactory::CLIENT_FEES];

            if (!$useOneyPrefix) {
                return $values;
            }

            $values = array_map(function ($data): string {
                return 'oney_' . $data;
            }, $values);

            return $values;
        } catch(\Exception $exception) {
            return [];
        }
    }
}
