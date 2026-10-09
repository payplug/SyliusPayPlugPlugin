<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Provider\CaptureHostedPaymentRequestCommandProvider;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Resolver\SelectedCardResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CaptureHostedPaymentRequestCommandProviderTest extends TestCase
{
    private const SELECTED_CARD_ID = 17;

    private RequestStack $requestStack;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private CaptureHostedPaymentRequestCommandProvider $provider;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);

        $this->provider = new CaptureHostedPaymentRequestCommandProvider(new SelectedCardResolver($this->requestStack, $this->payplugCardRepository));
    }

    private function paymentRequestWithDetails(array $details): PaymentRequestInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn($details);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getId')->willReturn('1');

        return $paymentRequest;
    }

    public function testProvide_withNoCardSelected_returnsCaptureHostedPaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithDetails([]);

        self::assertInstanceOf(CaptureHostedPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withOtherCardSentinelSelected_returnsCaptureHostedPaymentRequest(): void
    {
        $this->requestStack->getSession()->set('payplug_payment_method', 'other');
        $paymentRequest = $this->paymentRequestWithDetails([]);

        self::assertInstanceOf(CaptureHostedPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withExistingCardSelectedAndNotYetCaptured_returnsCaptureAliasPaymentRequest(): void
    {
        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn(new Card());
        $paymentRequest = $this->paymentRequestWithDetails([]);

        self::assertInstanceOf(CaptureAliasPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withExistingCardSelectedButAlreadyCaptured_returnsOfflineCaptureRequest(): void
    {
        $card = new Card();
        $card->setExternalId('alias_existing_1');

        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn($card);
        $paymentRequest = $this->paymentRequestWithDetails([
            'alias_payment_created_at' => '2026-08-17T10:00:00+00:00',
            'alias_id' => 'alias_existing_1',
        ]);

        self::assertInstanceOf(OfflineCapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withDifferentCardSelectedAfterEarlierAliasAttempt_returnsFreshCaptureAliasPaymentRequest(): void
    {
        $card = new Card();
        $card->setExternalId('alias_new');

        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn($card);
        $paymentRequest = $this->paymentRequestWithDetails([
            'alias_payment_created_at' => '2026-08-17T10:00:00+00:00',
            'alias_id' => 'alias_old',
        ]);

        self::assertInstanceOf(CaptureAliasPaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withNoCardSelectedAfterEarlierAliasAttempt_returnsOfflineCaptureRequestInsteadOfADuplicateCapture(): void
    {
        $paymentRequest = $this->paymentRequestWithDetails([
            'alias_payment_created_at' => '2026-08-17T10:00:00+00:00',
            'alias_id' => 'alias_old',
        ]);

        self::assertInstanceOf(OfflineCapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withHostedFieldsTokenAlreadyCaptured_returnsOfflineCaptureRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithDetails(['hosted_fields_created_at' => '2026-08-17T10:00:00+00:00']);

        self::assertInstanceOf(OfflineCapturePaymentRequest::class, $this->provider->provide($paymentRequest));
    }

    public function testProvide_withSelectedCardIdNoLongerFound_fallsBackToCaptureHostedPaymentRequest(): void
    {
        $this->requestStack->getSession()->set('payplug_payment_method', self::SELECTED_CARD_ID);
        $this->payplugCardRepository->method('find')->with(self::SELECTED_CARD_ID)->willReturn(null);
        $paymentRequest = $this->paymentRequestWithDetails([]);

        self::assertInstanceOf(CaptureHostedPaymentRequest::class, $this->provider->provide($paymentRequest));
    }
}
