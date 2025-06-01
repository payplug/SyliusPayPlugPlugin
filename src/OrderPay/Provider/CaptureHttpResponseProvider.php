<?php

namespace PayPlug\SyliusPayPlugPlugin\OrderPay\Provider;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_apple_pay',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.http_response_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
class CaptureHttpResponseProvider implements HttpResponseProviderInterface
{
    public function supports(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest,): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE &&
            ($paymentRequest->getResponseData()['redirect_url'] ?? null) !== null;
    }

    public function getResponse(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): Response {
        // This is called after the capture payment request has been handled
        $data = $paymentRequest->getResponseData();
        return new RedirectResponse($data['redirect_url']);
    }
}
