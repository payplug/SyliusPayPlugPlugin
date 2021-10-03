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

In the channel settings, the base currency must be set to **EUR** because the payment gateway only works in this currency. 

In local environment, the plugin will not work properly because you will not be notified of the status of payments from the payment gateway.

> #### ⚠️ Refunds requirements 
> You need to make some adjustments in order to make our plugin worked normally due to a dependency to [refund-plugin](https://github.com/Sylius/RefundPlugin). Please follow those requirements:
> 1. To generate "Credit memos" when refunding, your server need to have the [**WKHTMLTOPDF**](https://wkhtmltopdf.org/) binary as explain in [refund-pluging documentation # Pre-requirements](https://github.com/Sylius/RefundPlugin/tree/master#pre---requirements)
> 2. To use proper traits and interfaces, apply changes as explain in [refund-plugin documentation # Installation - Step 4](https://github.com/Sylius/RefundPlugin/tree/master#installation) 

## Compatibility

| | Version |
| :--- | :--- |
| PHP  | 7.3, 7.4 |
| Sylius | 1.8, 1.9 |

## Installation

1. If you don't use [**symfony/messenger**](https://packagist.org/packages/symfony/messenger) component yet, it is required to configure one of the message buses as a default bus in file `config/packages/framework.yaml`:

    ```yaml
    framework:
        messenger:
            default_bus: sylius_refund_plugin.command_bus
    ```

2. As this plugin has a dependency to [**sylius/refund-plugin**](https://packagist.org/packages/sylius/refund-plugin) which does not yet have a stable release, configure your project to accept release candidate version.

    ```bash
    composer config minimum-stability rc
    composer config prefer-stable true
    ```

3. Require the **payplug/sylius-payplug-plugin** :

    ```bash
    composer config extra.symfony.allow-contrib true
    composer require payplug/sylius-payplug-plugin
    ```

4. Apply migrations to your database:

    ```shell
    bin/console doctrine:migrations:migrate
    ```

5. Copy templates that are overridden by Sylius into `templates/bundles/SyliusAdminBundle`
    
    ```shell
    mkdir -p templates/bundles/SyliusAdminBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusAdminBundle/* templates/bundles/SyliusAdminBundle/
    ```

6. Add PayPlug to refundable payment method for Sylius Refund Plugin in `config/services.yaml`

    ```yaml
    parameters:
        sylius_refund.supported_gateways:
            - payplug
            - payplug_oney
    ```

7. Add PayPlug routes in `config/routes.yaml`

   ```yaml
   sylius_payplug:
      resource: "@PayPlugSyliusPayPlugPlugin/Resources/config/routing.yaml"
   ```

8. Add Traits for Customer and PaymentMethod entities

* App\Entity\Customer\Customer

   ```php
   <?php

   declare(strict_types=1);

   namespace App\Entity\Customer;

   use Doctrine\ORM\Mapping as ORM;
   use PayPlug\SyliusPayPlugPlugin\Entity\Traits\CustomerTrait;
   use Sylius\Component\Core\Model\Customer as BaseCustomer;

   /**
   * @ORM\Entity
   * @ORM\Table(name="sylius_customer")
   */
   class Customer extends BaseCustomer
   {
      use CustomerTrait;
   }
   ``` 

* App\Entity\Payment\PaymentMethod

   ```php
   <?php
   
   declare(strict_types=1);
   
   namespace App\Entity\Payment;
   
   use Doctrine\ORM\Mapping as ORM;
   use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentMethodTrait;
   use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;
   use Sylius\Component\Payment\Model\PaymentMethodTranslationInterface;
   
   /**
    * @ORM\Entity
    * @ORM\Table(name="sylius_payment_method")
    */
   class PaymentMethod extends BasePaymentMethod
   {
       use PaymentMethodTrait;
   
       protected function createTranslation(): PaymentMethodTranslationInterface
       {
           return new PaymentMethodTranslation();
       }
   }
   ``` 

9. Process translations

    ```bash
    php bin/console translation:update en PayPlugSyliusPayPlugPlugin --dump-messages
    php bin/console translation:update fr PayPlugSyliusPayPlugPlugin --dump-messages
    ```

10. Clear cache:

     ```shell
     php bin/console cache:clear
     ```

🎉 You are now ready to add Payplug Payment method.
In your back-office, go to `Configuration > Payment methods`, then click on `Create` and choose "**PayPlug**".

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
    mkdir -p templates/bundles/SyliusUiBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusAdminBundle/* templates/bundles/SyliusAdminBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusShopBundle/* templates/bundles/SyliusShopBundle/
    cp -R vendor/payplug/sylius-payplug-plugin/src/Resources/views/SyliusUiBundle/* templates/bundles/SyliusUiBundle/
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

## Oney Integration

For better Oney integration, you can check the [Oney enhancement documentation](doc/oney_enhancement.md).

## Doc
- [Development](doc/development.md)
- [Release Process](RELEASE.md)
