<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ShowMeaExtension extends AbstractExtension
{
    /** @var string */
    public $localeCode;

    /** @var PayPlugApiClientInterface */
    private $oneyClient;

    public function __construct(LocaleContextInterface $localeContext, PayPlugApiClientInterface $oneyClient)
    {
        $this->localeCode = \strtoupper($localeContext->getLocaleCode());
        if (\mb_strlen($this->localeCode) === 5) {
            $this->localeCode = \mb_substr($this->localeCode, 0, 2);
        }
        $this->oneyClient = $oneyClient;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_show_mea', [$this, 'canShowMEA']),
        ];
    }

    public function canShowMEA(): bool
    {
        /** @var string|null $country */
        $country = $this->oneyClient->getAccount()['country'];

        if (!\is_string($country)) {
            return false;
        }

        if ($this->localeCode === $this->oneyClient->getAccount()['country']) {
            return true;
        }

        return false;
    }
}
