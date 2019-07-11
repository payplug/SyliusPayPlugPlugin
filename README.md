## Overview

This plugin allows you to integrate PayPlug payment with Sylius platform app.

## Installation

Add a package to your private repository and add the repository to `composer.json`:

```json
{
    "minimum-stability": "dev",
    "repositories": [
        {
          "type": "vcs",
          "url": "<your URL to private repository>"
        }
    ]
}
```

1. Require plugin with composer:

    ```bash
    composer require payplug/payplug-sylius
    ```

2. Import configuration in your `config/packages/_sylius.yaml` file:

    ```yaml
    imports:
        - { resource: "@PayPlugSyliusPayPlugPlugin/Resources/config/config.yml" }
    ```

3. Add plugin class to your `config/bundles.php` file:

    ```php
    $bundles = [
        PayPlug\SyliusPayPlugPlugin\PayPlugSyliusPayPlugPlugin::class => ['all' => true],
    ];
    ```

4. Clear cache:

    ```bash
    bin/console cache:clear
    ```
    
## Requirements
 
In the channel settings, the base currency must be set to EUR because the payment gateway only works in this currency. The plugin in the local environment will not work properly because you will not be notified of the status of payments from the payment gateway

## Cron job

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

## Testing

```bash
$ composer install
$ cd tests/Application
$ yarn install
$ yarn run gulp
$ bin/console assets:install web -e test
$ bin/console doctrine:database:create -e test
$ bin/console doctrine:schema:create -e test
$ bin/console server:run 127.0.0.1:8080 -d web -e test
$ open http://localhost:8080
$ bin/behat
$ bin/phpspec run
```

## Contribution

Learn more about our contribution workflow on http://docs.sylius.org/en/latest/contributing/.
