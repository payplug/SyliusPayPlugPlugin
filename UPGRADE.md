# Deprecations

## The Payum wiring — removal planned for early 2027

The plugin no longer uses Payum. The live payment flow is Sylius 2.x command/response providers,
declared in `config/services.yaml` and implemented in `src/Command/Handler/`.

The Payum code left in the tree is inert: `PayPlugSyliusPayPlugExtension::load()` does not load
`config/services/gateway.xml`, which holds the only `payum.gateway_factory_builder` tags, so no
Payum gateway is ever registered for the `payplug*` factory names. The actions in `src/Action/` are
still tagged `payum.action` in the compiled container (via `#[AutoconfigureTag]` attributes on the
classes), but with no gateway to attach to they are never executed.

The following are deprecated and **will be removed permanently in early 2027**:

- `PayPlug\SyliusPayPlugPlugin\Action\CaptureAction`
- `PayPlug\SyliusPayPlugPlugin\Action\StatusAction`
- `PayPlug\SyliusPayPlugPlugin\Action\NotifyAction`
- `PayPlug\SyliusPayPlugPlugin\Action\ConvertPaymentAction`
- `PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait::setApi()` (the trait's `$payPlugApiClient`
  property is **not** deprecated — it is still used by `Controller\OneClickAction`)
- `PayPlug\SyliusPayPlugPlugin\Gateway\AbstractGatewayFactory::populateConfig()` (the class itself
  is **not** deprecated — it remains the home of `FACTORY_NAME`, `FACTORY_TITLE` and
  `BASE_CURRENCY_CODE`)
- `config/services/gateway.xml`

**If you do not import `config/services/gateway.xml` in your own application, this affects you in no
way** — none of the above runs today, and there is nothing to migrate.

If you *do* import it to keep using Payum, that escape hatch stops working at the removal date. Move
to the command handlers in `src/Command/Handler/` before then.

# Upgrading from 1.0.0

1. Skip the faulty migration

`php bin/console doctrine:migrations:version "PayPlug\SyliusPayPlugPlugin\Migrations\Version20210410143918" --add`

2. Execute the new migrations to keep the database up to date

`php bin/console doctrine:migration:migrate`

4. Create a new migration to fix the old one (Version20210410143918)

`php bin/console doctrine:migrations:diff --namespace="App\Migrations" --formatted`

6. Execute the new migration

`php bin/console doctrine:migration:migrate`

# Upgrading to 1.2.0

Add Traits for Customer and PaymentMethod entities

1. `App\Entity\Customer\Customer`


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

2. `App\Entity\Payment\PaymentMethod`


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

Run the migration migrate utility to keep the database up to date

`php bin/console doctrine:migration:migrate`
