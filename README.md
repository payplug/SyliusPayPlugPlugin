[![License](https://img.shields.io/packagist/l/payplug/sylius-payplug-plugin.svg)](https://github.com/payplug/SyliusPayPlugPlugin/blob/master/LICENSE)
[![CI - Analysis](https://github.com/payplug/SyliusPayPlugPlugin/actions/workflows/analysis.yaml/badge.svg?branch=master)](https://github.com/payplug/SyliusPayPlugPlugin/actions/workflows/analysis.yaml)
[![CI - Sylius](https://github.com/payplug/SyliusPayPlugPlugin/actions/workflows/sylius.yaml/badge.svg?branch=master)](https://github.com/payplug/SyliusPayPlugPlugin/actions/workflows/sylius.yaml)
[![Version](https://img.shields.io/packagist/v/payplug/sylius-payplug-plugin.svg)](https://packagist.org/packages/payplug/sylius-payplug-plugin)
[![Total Downloads](https://poser.pugx.org/payplug/sylius-payplug-plugin/downloads)](https://packagist.org/packages/payplug/sylius-payplug-plugin)

<p align="center">
    <a href="https://sylius.com" target="_blank">
        <picture>
         <source media="(prefers-color-scheme: dark)" srcset="https://media.sylius.com/sylius-logo-800-dark.png">
         <source media="(prefers-color-scheme: light)" srcset="https://media.sylius.com/sylius-logo-800.png">
         <img alt="Sylius Logo." src="https://media.sylius.com/sylius-logo-800.png">
        </picture>
    </a>
</p>

<h1 align="center">Payplug payment plugin for Sylius</h1>

<p align="center">This plugin allows you to integrate Payplug payment with Sylius platform app including payment features and refunding orders.</p>

## Requirements

In the channel settings, the base currency must be set to **EUR** because the payment gateway only works in this currency. 

In local environment, the plugin will not work properly because you will not be notified of the status of payments from the payment gateway.

> #### ⚠️ Refunds requirements 
> You need to make some adjustments in order to make our plugin worked normally due to a dependency to [refund-plugin](https://github.com/Sylius/RefundPlugin). Please follow those requirements:
> 
> To generate "Credit memos" when refunding, your server need to have the [**WKHTMLTOPDF**](https://wkhtmltopdf.org/) binary as explain in [refund-pluging documentation # Pre-requirements](https://github.com/Sylius/RefundPlugin/tree/master#pre---requirements)

## Compatibility

|        | Version |
|:-------|:--------|
| PHP    | ^8.2    |
| Sylius | ^2.0    |

## Installation

Choose the installation method that matches your setup:

- [With Symfony Flex](#with-symfony-flex-recommended) (recommended)
- [Without Symfony Flex](#without-symfony-flex-manual)

---

### With Symfony Flex (recommended)

#### 1. Allow contrib recipes and require the plugin

```bash
composer config extra.symfony.allow-contrib true
composer require payplug/sylius-payplug-plugin
```

#### 2. Install the Flex recipe

```bash
composer recipes:install payplug/sylius-payplug-plugin --force
```

This automatically registers the bundle, copies configuration files, and sets up assets (on Sylius 2.1+).

#### 3. Apply migrations to your database

```shell
bin/console doctrine:migrations:migrate
```

#### 4. Add Payplug to refundable payment methods for Sylius Refund Plugin in `config/services.yaml`

```yaml
parameters:
    locale: fr_FR
    sylius_refund.supported_gateways:
        - payplug
        - payplug_oney
        - payplug_bancontact
        - payplug_apple_pay
        - payplug_american_express
```

#### 5. Add Traits for Customer and PaymentMethod entities

* App\Entity\Customer\Customer

```php
<?php

declare(strict_types=1);

namespace App\Entity\Customer;

use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\CardsOwnerInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\CustomerTrait;
use Sylius\Component\Core\Model\Customer as BaseCustomer;

/**
* @ORM\Entity
* @ORM\Table(name="sylius_customer")
*/
#[ORM\Entity]
#[ORM\Table(name: 'sylius_customer')]
class Customer extends BaseCustomer implements CardsOwnerInterface
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
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment_method')]
class PaymentMethod extends BasePaymentMethod
{
   use PaymentMethodTrait;

   protected function createTranslation(): PaymentMethodTranslationInterface
   {
       return new PaymentMethodTranslation();
   }
}
```

* App\Entity\Payment\Payment

```php
<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentTrait;
use Sylius\Component\Core\Model\Payment as BasePayment;

/**
* @ORM\Entity
* @ORM\Table(name="sylius_payment")
*/
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment')]
class Payment extends BasePayment
{
  use PaymentTrait;
}
```

#### 6. Process translations

```bash
php bin/console translation:extract en PayPlugSyliusPayPlugPlugin --dump-messages
php bin/console translation:extract fr PayPlugSyliusPayPlugPlugin --dump-messages
```

#### 7. Clear cache

```bash
bin/console cache:clear
```

🎉 You are now ready to add Payplug Payment method.
In your back-office, go to `Configuration > Payment methods`, then click on `Create` and choose "**Payplug**".

---

### Without Symfony Flex (manual)

#### 1. Require the **payplug/sylius-payplug-plugin**

```bash
composer require payplug/sylius-payplug-plugin
```

#### 2. Register Sylius resources

The plugin's extension does not prepend its `resources.yaml`, so the Sylius resource services for the Card and RefundHistory entities are never created. Add them manually in `config/packages/sylius_resource.yaml`:

```yaml
sylius_resource:
    resources:
        payplug.payplug_card:
            driver: doctrine/orm
            classes:
                model: PayPlug\SyliusPayPlugPlugin\Entity\Card
        payplug.payplug_refund_history:
            driver: doctrine/orm
            classes:
                model: PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory
                repository: PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepository
```

#### 3. Fix service autowiring

The plugin uses `#[Autoconfigure]` on some actions and relies on named constructor arguments that Symfony cannot resolve automatically. Add the following to `config/services.yaml`:

```yaml
services:
    PayPlug\SyliusPayPlugPlugin\Action\CaptureAction:
        arguments:
            $payplugCardRepository: '@payplug.repository.payplug_card'

    PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface:
        alias: payplug.repository.payplug_refund_history
```

#### 4. Apply migrations to your database

```shell
bin/console doctrine:migrations:migrate
```

#### 5. Add Payplug to refundable payment methods for Sylius Refund Plugin in `config/services.yaml`

```yaml
parameters:
    locale: fr_FR
    sylius_refund.supported_gateways:
        - payplug
        - payplug_oney
        - payplug_bancontact
        - payplug_apple_pay
        - payplug_american_express
```

#### 6. Add Traits for Customer and PaymentMethod entities

* App\Entity\Customer\Customer

```php
<?php

declare(strict_types=1);

namespace App\Entity\Customer;

use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\CardsOwnerInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\CustomerTrait;
use Sylius\Component\Core\Model\Customer as BaseCustomer;

/**
* @ORM\Entity
* @ORM\Table(name="sylius_customer")
*/
#[ORM\Entity]
#[ORM\Table(name: 'sylius_customer')]
class Customer extends BaseCustomer implements CardsOwnerInterface
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
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment_method')]
class PaymentMethod extends BasePaymentMethod
{
   use PaymentMethodTrait;

   protected function createTranslation(): PaymentMethodTranslationInterface
   {
       return new PaymentMethodTranslation();
   }
}
```

* App\Entity\Payment\Payment

```php
<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentTrait;
use Sylius\Component\Core\Model\Payment as BasePayment;

/**
* @ORM\Entity
* @ORM\Table(name="sylius_payment")
*/
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment')]
class Payment extends BasePayment
{
  use PaymentTrait;
}
```

#### 7. Process translations

```bash
php bin/console translation:extract en PayPlugSyliusPayPlugPlugin --dump-messages
php bin/console translation:extract fr PayPlugSyliusPayPlugPlugin --dump-messages
```

#### 8. Clear cache

```bash
bin/console cache:clear
```

🎉 You are now ready to add Payplug Payment method.
In your back-office, go to `Configuration > Payment methods`, then click on `Create` and choose "**Payplug**".

#### Assets installation (only for Sylius 2.0.x)

On Sylius 2.0.x, there is no automatic load of assets.
You need to add the following lines in `assets/shop/controllers.json` to allow Sylius to use our assets:

```json
{
    "controllers": {
        "@payplug/sylius-payplug-plugin": {
            "oney-popin": {
                "enabled": true,
                "fetch": "lazy",
                "autoimport": {
                    "@payplug/sylius-payplug-plugin/shop/dist/oney_common/index.css": true,
                    "@payplug/sylius-payplug-plugin/shop/dist/oney_popin/index.css": true
                }
            },
            "integrated-payment": {
                "enabled": true,
                "fetch": "lazy",
                "autoimport": {
                    "@payplug/sylius-payplug-plugin/shop/dist/payment/integrated.css": true
                }
            },
            "oney-payment": {
                "enabled": true,
                "fetch": "lazy"
            },
            "payment-logo": {
                "enabled": true,
                "fetch": "lazy"
            },
            "checkout-select-payment": {
                "enabled": true,
                "fetch": "lazy",
                "autoimport": {
                    "@payplug/sylius-payplug-plugin/shop/dist/payment/index.css": true
                }
            },
            "apple-pay": {
                "enabled": true,
                "fetch": "lazy"
            }
        }
    },
    "entrypoints": []
}
```

> [!NOTE]
> On Sylius Standard >= 2.1, assets are automatically loaded when you install the plugin with Flex.
> If you are upgrading from a 2.0.x version, read the [upgrade guide](https://github.com/Sylius/Sylius/blob/2.1/UPGRADE-2.1.md#assets)


## Logs

If you want to follow the logs in the production environment, you need to add the configuration in `config/packages/prod/monolog.yaml`, logs should be in `var/log/prod.log` which can be searched after the phrase `[Payum]` or `[Payplug]`:

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

## Development

See [How to contribute](CONTRIBUTING.md).

## License

This library is under the MIT license.

## Oney Integration

For better Oney integration, you can check the [Oney enhancement documentation](doc/oney_enhancement.md).

## Authorized Payment

Since 1.11.0, the plugin supports the authorized payment feature. You can check the [Authorized Payment documentation](doc/authorized_payment.md).

## Doc
- [Development](doc/development.md)
- [Release Process](RELEASE.md)
