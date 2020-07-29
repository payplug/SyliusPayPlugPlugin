[![License](https://img.shields.io/packagist/l/payplug/payplug-sylius.svg)](https://github.com/payplug/SyliusPayPlugPlugin/blob/master/LICENSE)
![CI](https://github.com/payplug/SyliusPayPlugPlugin/workflows/CI/badge.svg?branch=master)
[![Version](https://img.shields.io/packagist/v/payplug/payplug-sylius.svg)](https://packagist.org/packages/payplug/payplug-sylius)
[![Total Downloads](https://poser.pugx.org/payplug/payplug-sylius/downloads)](https://packagist.org/packages/payplug/payplug-sylius)

<p align="center">
    <a href="https://sylius.com" target="_blank">
        <img src="https://demo.sylius.com/assets/shop/img/logo.png" />
    </a>
</p>

<h1 align="center">PayPlug payment plugin for Sylius</h1>

<p align="center">This plugin allows you to integrate PayPlug payment with Sylius platform app including payment features and refunding orders.</p>

## Requirements
In the channel settings, the base currency must be set to EUR because the payment gateway only works in this currency. The plugin in the local environment will not work properly because you will not be notified of the status of payments from the payment gateway.

## Installation

1. Add the bundle and dependencies in your composer.json :

    With **Symfony Flex** :

        composer config extra.symfony.allow-contrib true
        composer require payplug/payplug-sylius

    Yon can now skip the next three steps.

    Or **manually** :

        composer require payplug/payplug-sylius

2. Enable the plugin in your `config/bundles.php` file by add

    ```php
    PayPlug\SyliusPayPlugPlugin\PayPlugSyliusPayPlugPlugin::class => ['all' => true],
    ```

3. Import required config and routes:

For file `config/packages/sylius_payplug.yaml`

    ```yaml
    imports:
        - { resource: "@PayPlugSyliusPayPlugPlugin/Resources/config/config.yml" }
        - { resource: "@PayPlugSyliusPayPlugPlugin/Resources/config/services.xml" }
    ```
   
For file `config/routes.yaml`

    ```yaml
    sylius_refund_complete_refund_payment:
        path: /admin/orders/{orderNumber}/refund-payments/{id}/complete
        methods: [POST]
        defaults:
            _controller: PayPlug\SyliusPayPlugPlugin\Action\Admin\CompleteRefundPaymentAction
        prefix: /admin
    
    sylius_refund_refund_units:
        path: /admin/orders/{orderNumber}/refund-units
        defaults:
            _controller: PayPlug\SyliusPayPlugPlugin\Action\Admin\RefundUnitsAction
        prefix: /admin
    ```
   
4. Copy templates and migrations
    ```shell
    cp -R vendor/sylius/refund-plugin/migrations/* src/Migrations
    cp -R vendor/payplug/sylius-payplug-plugin/src/Migrations/* src/Migrations
    bin/console doctrine:migrations:migrate
    mkdir -p templates/bundles/SyliusAdminBundle/
    cp -R vendor/sylius/refund-plugin/src/Resources/views/SyliusAdminBundle/* templates/bundles/SyliusAdminBundle/
    ```
5. (optional) If you don't use symfony/messenger component yet, it is required to configure one of the message buses as a default bus:

    ```yaml
    framework:
        messenger:
            default_bus: sylius_refund_plugin.command_bus
    ```

6. Add PayPlug to refundable payment method for Sylius Refund Plugin

    ```yaml
    parameters:
        sylius_refund.supported_gateways:
            - payplug
    ```

7. Dump assets and clear cache:

    ```shell
    bin/console assets:install public --symlink
    bin/console cache:clear
    ```
    
## Cronjob
In the case when the IPN is blocked, you can set cron job every minute that updates the payment status.

For example:

```bash
* * * * * bin/console payplug:update-payment-state
```

## Logs

If you want to follow the logs in the production environment, you need to add the configuration in `config/packages/prod/monolog.yaml`, logs should be in `var/log/prod.log` which can be searched after the phrase `[Payum]` or `[PayPlug]`:

 ```yaml
   monolog:
       handlers:
          ...
          
          payum:
              level: debug
              type: stream
              path: "%kernel.logs_dir%/%kernel.environment%.log"
```

## IPN testing on the local machine

In the configuration of the payment gateway in the admin panel, set your url (eg from [ngrok](https://ngrok.com/)) to notifications in the field `Notification url for environment dev`. This url will only work in the dev environment.
 
## Customization

### Available services you can [decorate](https://symfony.com/doc/current/service_container/service_decoration.html) and forms you can [extend](http://symfony.com/doc/current/form/create_form_type_extension.html)

Run the below command to see what Symfony services are shared with this plugin:
 
```bash
$ bin/console debug:container payplug_sylius_payplug_plugin
```

### Template overriding

This plugin override some sylius templates. 
If you plan override them also, you should retrieve them in your application.

Copy Sylius templates overridden in plugin to your templates directory (e.g templates/bundles/)

   ```shell
    mkdir -p templates/bundles/SyliusAdminBundle/
    mkdir -p templates/bundles/SyliusShopBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusAdminBundle/* templates/bundles/SyliusAdminBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusShopBundle/* templates/bundles/SyliusShopBundle/
    ```

### Assets

For the sake of quickness, the usage of a tool like [Parcel](https://github.com/parcel-bundler/parcel/tree/1.x) shows that its efficiency is indeed undeniable.

So, if you want to edit assets (js, scss, ...) you'll likely go into `src/Resources/dev` and run `yarn install`.

Then, you'll find a list of commands inside `package.json` which are :

```bash
$ (cd src/Resources/dev && yarn build)
``` 

Or, if you prefer the dev mode; a `watch` command that compile in real time, then run:

```bash
$ (cd src/Resources/dev && yarn dev)
``` 

You can add any resources as far as Parcel can go, but those have to be located in `/pages` otherwize they won't be compiled.

Assets can be found in `src/Resources/public/assets/oney` so you'll have to install them in your application by running:

```bash
$ bin/console assets:install --symlink
# or
$ bin/console sylius:theme:assets:install --symlink # e.g if bootstrapTheme is enabled 
``` 

To make it fully compatible with [Sylius Bootstrap Theme](https://github.com/Sylius/BootstrapTheme), some lines have to be added to ̀the main entrypoint (such as `app.js`) of the theme:

```js
const $ = require('jquery');
global.$ = global.jQuery = $;
```

## Testing

```bash
$ composer install
$ cd tests/Application
$ yarn install
$ yarn build
$ bin/console assets:install public -e test
$ bin/console doctrine:database:create -e test
$ bin/console doctrine:schema:create -e test
$ bin/console server:run 127.0.0.1:8080 -d public -e test
$ open http://localhost:8080
$ bin/behat
$ bin/phpspec run
```

## License

This library is under the MIT license.
