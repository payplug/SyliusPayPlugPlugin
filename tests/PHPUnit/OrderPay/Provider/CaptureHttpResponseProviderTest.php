<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\OrderPay\Provider;

use PayPlug\SyliusPayPlugPlugin\OrderPay\Provider\CaptureHttpResponseProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class CaptureHttpResponseProviderTest extends TestCase
{
    private CaptureHttpResponseProvider $provider;

    private RequestConfiguration&MockObject $requestConfiguration;

    protected function setUp(): void
    {
        $this->provider = new CaptureHttpResponseProvider();
        $this->requestConfiguration = $this->createMock(RequestConfiguration::class);
    }

    private function paymentRequest(string $action, array $responseData): PaymentRequestInterface&MockObject
    {
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getAction')->willReturn($action);
        $paymentRequest->method('getResponseData')->willReturn($responseData);

        return $paymentRequest;
    }

    public function testSupports_whenRedirectUrlIsSetOnCapture_returnsTrue(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, ['redirect_url' => 'https://example.com/3ds']);

        self::assertTrue($this->provider->supports($this->requestConfiguration, $paymentRequest));
    }

    public function testSupports_whenRedirectHtmlIsSetOnCapture_returnsTrue(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, ['redirect_html' => '<html></html>']);

        self::assertTrue($this->provider->supports($this->requestConfiguration, $paymentRequest));
    }

    public function testSupports_whenActionIsNotCapture_returnsFalse(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_NOTIFY, ['redirect_url' => 'https://example.com/3ds']);

        self::assertFalse($this->provider->supports($this->requestConfiguration, $paymentRequest));
    }

    public function testSupports_whenNeitherRedirectFieldIsSet_returnsFalse(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, ['status' => 'processing']);

        self::assertFalse($this->provider->supports($this->requestConfiguration, $paymentRequest));
    }

    public function testGetResponse_whenRedirectUrlIsSet_returnsARedirectResponse(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, ['redirect_url' => 'https://example.com/3ds']);

        $response = $this->provider->getResponse($this->requestConfiguration, $paymentRequest);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.com/3ds', $response->getTargetUrl());
    }

    public function testGetResponse_whenRedirectHtmlIsSet_returnsThatHtmlAsTheResponseContent(): void
    {
        $html = '<html><body>3DS challenge form</body></html>';
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, ['redirect_html' => $html]);

        $response = $this->provider->getResponse($this->requestConfiguration, $paymentRequest);

        self::assertSame($html, $response->getContent());
    }

    /**
     * Not a real-world case (the handler only ever sets one or the other), but proves the
     * precedence explicitly rather than leaving it implicit: redirect_html wins if both are set.
     */
    public function testGetResponse_whenBothRedirectFieldsAreSet_prefersRedirectHtml(): void
    {
        $html = '<html><body>3DS challenge form</body></html>';
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, [
            'redirect_url' => 'https://example.com/3ds',
            'redirect_html' => $html,
        ]);

        $response = $this->provider->getResponse($this->requestConfiguration, $paymentRequest);

        self::assertSame($html, $response->getContent());
    }

    public function testGetResponse_whenNeitherRedirectFieldIsSet_throws(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, []);

        $this->expectException(\LogicException::class);

        $this->provider->getResponse($this->requestConfiguration, $paymentRequest);
    }
}
