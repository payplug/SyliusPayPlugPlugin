<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

abstract class AbstractGatewayFactory extends GatewayFactory
{
    public const FACTORY_NAME = null;

    public const FACTORY_TITLE = null;

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => static::FACTORY_NAME,
            'payum.factory_title' => static::FACTORY_TITLE,
            'payum.http_client' => '@payplug_sylius_payplug_plugin.api_client.payplug',
        ]);

        if (false !== (bool) $config['payum.api']) {
            return;
        }

        $config['payum.default_options'] = [
            'secretKey' => null,
        ];

        $config->defaults($config['payum.default_options']);

        $config['payum.required_options'] = [
            'secretKey',
        ];

        $config['payum.api'] = function (ArrayObject $formConfig): PayPlugApiClientInterface {
            $formConfig->validateNotEmpty($formConfig['payum.required_options']);

            return new PayPlugApiClient($formConfig['secretKey'], static::FACTORY_NAME);
        };
    }
}
