<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;

class CanSaveCardChecker implements CanSaveCardCheckerInterface
{
    /** @var CustomerContextInterface */
    private $customerContext;

    public function __construct(CustomerContextInterface $customerContext)
    {
        $this->customerContext = $customerContext;
    }

    public function isAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        if (!$this->customerContext->getCustomer() instanceof CustomerInterface) {
            return false;
        }

        $gatewayConfiguration = $paymentMethod->getGatewayConfig();

        if (!$gatewayConfiguration instanceof GatewayConfigInterface) {
            return false;
        }

        if (!\array_key_exists(PayPlugGatewayFactory::ONE_CLICK, $gatewayConfiguration->getConfig())) {
            return false;
        }

        return (bool) $gatewayConfiguration->getConfig()[PayPlugGatewayFactory::ONE_CLICK] ?? false;
    }
}
