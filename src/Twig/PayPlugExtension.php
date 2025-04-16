<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PayPlugExtension extends AbstractExtension
{
    public function __construct(
        private CanSaveCardCheckerInterface $canSaveCardChecker,
        private PayPlugApiClientFactory $apiClientFactory,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_save_card_enabled', $this->isSaveCardAllowed(...)),
            new TwigFunction('is_payplug_test_mode_enabled', $this->isTest(...)),
        ];
    }

    public function isSaveCardAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->canSaveCardChecker->isAllowed($paymentMethod);
    }

    public function isTest(PaymentMethodInterface $paymentMethod): bool
    {
        $client = $this->apiClientFactory->createForPaymentMethod($paymentMethod);

        return !(bool) $client->getAccount()['is_live'];
    }
}
