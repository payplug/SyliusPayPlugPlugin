<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ShowMeaExtension extends AbstractExtension
{
    /** @var PayPlugApiClientInterface */
    private $oneyClient;

    /** @var LocaleContextInterface */
    private $localeContext;

    /** @var string */
    public $localeCode;

    public function __construct(LocaleContextInterface $localeContext, PayPlugApiClientInterface $oneyClient)
    {
        $this->localeContext = $localeContext;
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

        if ($this->getLocaleCode() === $this->oneyClient->getAccount()['country']) {
            return true;
        }

        return false;
    }

    private function getLocaleCode(): string
    {
        if (null !== $this->localeCode) {
            return $this->localeCode;
        }

        $this->localeCode = \strtoupper($this->localeContext->getLocaleCode());
        if (5 === \mb_strlen($this->localeCode)) {
            $this->localeCode = \mb_substr($this->localeCode, 0, 2);
        }

        return $this->localeCode;
    }
}
