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
    /** @var CanSaveCardCheckerInterface */
    private $canSaveCardChecker;
    private PayPlugApiClientFactory $apiClientFactory;

    public function __construct(
        CanSaveCardCheckerInterface $canSaveCardChecker,
        PayPlugApiClientFactory $apiClientFactory,
    ) {
        $this->canSaveCardChecker = $canSaveCardChecker;
        $this->apiClientFactory = $apiClientFactory;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_save_card_enabled', [$this, 'isSaveCardAllowed']),
            new TwigFunction('is_payplug_live', [$this, 'isLive']),
        ];
    }

    public function isSaveCardAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->canSaveCardChecker->isAllowed($paymentMethod);
    }

    public function isLive(PaymentMethodInterface $paymentMethod): bool
    {
        $client = $this->apiClientFactory->createForPaymentMethod($paymentMethod);

        return (bool) ($client->getAccount()['is_live']);
    }
}
