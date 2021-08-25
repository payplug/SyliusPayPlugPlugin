<?php

namespace PayPlug\SyliusPayPlugPlugin\Checker;

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

        if (!\array_key_exists('oneClick', $gatewayConfiguration->getConfig())) {
            return false;
        }

        return (bool) $gatewayConfiguration->getConfig()['oneClick'] ?? false;
    }
}
