<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ShowMeaExtension extends AbstractExtension
{
    /** @var string */
    public $localeCode;

    public function __construct(
        private LocaleContextInterface $localeContext,
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.oney')]
        private PayPlugApiClientInterface $oneyClient,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_show_mea', $this->canShowMEA(...)),
        ];
    }

    public function canShowMEA(): bool
    {
        /** @var string|null $country */
        $country = $this->oneyClient->getAccount()['country'];

        if (!\is_string($country)) {
            return false;
        }

        return $this->getLocaleCode() === $this->oneyClient->getAccount()['country'];
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
