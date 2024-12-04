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
    private CustomerContextInterface $customerContext;
    private PayplugFeatureChecker $payplugFeatureChecker;

    public function __construct(
        CustomerContextInterface $customerContext,
        PayplugFeatureChecker $payplugFeatureChecker,
    ) {
        $this->customerContext = $customerContext;
        $this->payplugFeatureChecker = $payplugFeatureChecker;
    }

    public function isAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        if (!$this->customerContext->getCustomer() instanceof CustomerInterface) {
            return false;
        }

        $this->payplugFeatureChecker->isOneClickEnabled($paymentMethod);
    }
}
