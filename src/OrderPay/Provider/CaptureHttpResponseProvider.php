<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\OrderPay\Provider;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_apple_pay',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_scalapay',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_wero',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE],
)]
class CaptureHttpResponseProvider implements HttpResponseProviderInterface
{
    public function supports(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest): bool
    {
        if ($paymentRequest->getAction() !== PaymentRequestInterface::ACTION_CAPTURE) {
            return false;
        }

        $data = $paymentRequest->getResponseData();

        return null !== ($data['redirect_url'] ?? null) || null !== ($data['redirect_html'] ?? null);
    }

    public function getResponse(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): Response {
        // This is called after the capture payment request has been handled
        $data = $paymentRequest->getResponseData();

        // The Unified API's "recommended for web" 3DS shape (Hosted Fields only, see
        // CaptureHostedPaymentRequestHandler): a self-submitting HTML form to render as-is, rather
        // than a plain redirect target.
        if (\is_string($data['redirect_html'] ?? null)) {
            return new Response($data['redirect_html']);
        }

        if (!\is_string($data['redirect_url'] ?? null)) {
            throw new \LogicException('Redirect URL is not set in the payment request response data.');
        }

        return new RedirectResponse($data['redirect_url']);
    }
}
