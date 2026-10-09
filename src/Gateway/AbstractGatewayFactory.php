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

    public const BASE_CURRENCY_CODE = 'EUR';

    /**
     * @deprecated Never called — this class only extends Payum's GatewayFactory for historical
     *             reasons. populateConfig() runs when Payum builds a gateway, and no Payum gateway
     *             is ever registered for the `payplug*` factory names: the only
     *             `payum.gateway_factory_builder` tags live in config/services/gateway.xml, which
     *             PayPlugSyliusPayPlugExtension::load() does not load. `payum.api` and
     *             `payum.http_client` are therefore never assigned.
     *
     *             The class itself is NOT deprecated — it remains the home of FACTORY_NAME,
     *             FACTORY_TITLE and BASE_CURRENCY_CODE, referenced throughout the plugin.
     *             Will be removed permanently in early 2027.
     */
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => static::FACTORY_NAME,
            'payum.factory_title' => static::FACTORY_TITLE,
            'payum.http_client' => '@payplug_sylius_payplug_plugin.api_client.payplug',
        ]);

        if ((bool) $config['payum.api']) {
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
