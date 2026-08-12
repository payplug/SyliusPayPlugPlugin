<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Twig\PayPlugExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class PayPlugExtensionTest extends TestCase
{
    private CanSaveCardCheckerInterface&MockObject $canSaveCardChecker;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PayPlugExtension $extension;

    protected function setUp(): void
    {
        $this->canSaveCardChecker = $this->createMock(CanSaveCardCheckerInterface::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);

        $this->extension = new PayPlugExtension($this->canSaveCardChecker, $this->apiClientFactory);
    }

    public function testHostedFieldsCompanyId_returnsCompanyIdFromAccount(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willReturn(['company_ref' => 'cmp_abc123']);
        $this->apiClientFactory->method('createForPaymentMethod')->with($paymentMethod)->willReturn($apiClient);

        self::assertSame('cmp_abc123', $this->extension->hostedFieldsCompanyId($paymentMethod));
    }

    public function testHostedFieldsCompanyId_missingCompanyIdKey_returnsEmptyString(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('getAccount')->willReturn([]);
        $this->apiClientFactory->method('createForPaymentMethod')->with($paymentMethod)->willReturn($apiClient);

        self::assertSame('', $this->extension->hostedFieldsCompanyId($paymentMethod));
    }
}
