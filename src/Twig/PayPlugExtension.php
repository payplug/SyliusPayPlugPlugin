<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PayPlugExtension extends AbstractExtension
{
    public function __construct(
        private CanSaveCardCheckerInterface $canSaveCardChecker,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_save_card_enabled', $this->isSaveCardAllowed(...)),
            new TwigFunction('is_payplug_test_mode_enabled', $this->isTest(...)),
            new TwigFunction('payplug_hosted_fields_company_id', $this->hostedFieldsCompanyId(...)),
            new TwigFunction('payplug_display_mode', $this->displayMode(...)),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function displayMode(array $config): ?string
    {
        return PayPlugGatewayFactory::resolveDisplayMode($config);
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

    public function hostedFieldsCompanyId(PaymentMethodInterface $paymentMethod): string
    {
        $client = $this->apiClientFactory->createForPaymentMethod($paymentMethod);
        $companyId = $client->getAccount()['company_ref'] ?? '';

        return \is_string($companyId) ? $companyId : '';
    }
}
