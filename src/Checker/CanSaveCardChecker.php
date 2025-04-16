<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;

class CanSaveCardChecker implements CanSaveCardCheckerInterface
{
    public function __construct(
        private CustomerContextInterface $customerContext,
        private PayplugFeatureChecker $payplugFeatureChecker,
    ) {
    }

    public function isAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        if (!$this->customerContext->getCustomer() instanceof CustomerInterface) {
            return false;
        }

        return $this->payplugFeatureChecker->isOneClickEnabled($paymentMethod);
    }
}
