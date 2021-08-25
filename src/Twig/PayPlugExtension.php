<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PayPlugExtension extends AbstractExtension
{
    /** @var \PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface */
    private $canSaveCardChecker;

    public function __construct(CanSaveCardCheckerInterface $canSaveCardChecker) {
        $this->canSaveCardChecker = $canSaveCardChecker;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_save_card_enabled', [$this, 'isSaveCardAllowed']),
        ];
    }

    public function isSaveCardAllowed(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->canSaveCardChecker->isAllowed($paymentMethod);
    }
}
