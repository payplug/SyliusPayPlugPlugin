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

# PPRO display eligibility: Bancontact, Wero, Scalapay (PRE-3677)

Bancontact, Wero and Scalapay are now shown at checkout only for the cart amounts and billing
countries PayPlug accepts for them, narrowed by the merchant on the payment method page. The
PayPlug limits are stored in each payment method's gateway config (`payment_method` key) instead of
being read from the PayPlug API on every checkout page.

What changes for a merchant already in production, in this order:

1. **The Scalapay minimum/maximum amount override is not carried over.** The `min_amount` and
   `max_amount` keys of a Scalapay gateway config are no longer read. After the upgrade the merchant
   range is empty, so it follows the PayPlug limits; a merchant who had narrowed the Scalapay range
   must enter it again on the payment method page.
2. **Until the PayPlug limits are first captured, these methods are not filtered** by amount or
   billing country (PayPlug still refuses what it does not accept at payment time). The limits are
   captured when the payment method page is opened in the admin, after an OAuth (re)connection, after
   a save that switches the LIVE mode, or after a payment refused by PayPlug for its country or
   amount. To capture them right after the upgrade, run once:

   ```bash
   bin/console payplug:ppro:refresh
   ```

   Payment methods not connected to a PayPlug account yet are reported as skipped. The command exits
   with a non-zero code if a connected payment method could not be refreshed (see the `payplug` log
   channel); run it again once PayPlug is reachable.
3. Every country allowed by PayPlug is accepted by default, and the merchant range is empty, which
   means "the PayPlug limits" (shown as the placeholder of the minimum and maximum fields). At least one country must stay selected, and an amount outside the PayPlug limits
   is refused when saving (the payment method is no longer saved and disabled instead).
4. **Fallback.** Without stored limits, or with stored limits that cannot be read, the payment method
   is shown and PayPlug arbitrates at payment time: this feature never blocks the checkout. When
   PayPlug refuses a payment for its billing country or amount, the limits are refreshed and the
   customer is sent back to the order page to choose another payment method; the payment request
   response data carries `redirect_url` and `error` for headless frontends.
5. The stored limits move only on the events listed in point 2, not on a schedule. An empty minimum
   or maximum, like a selection of every country, follows PayPlug's changes. A value the merchant
   entered is kept, and only adjusted when PayPlug narrows its limits below it.

For anyone extending the plugin:

| Removed / changed | Replacement |
| --- | --- |
| `Gateway\Form\Extension\ScalapayGatewayConfigurationTypeExtension` | `Gateway\Form\Extension\PproEligibilityTypeExtension`, on the Bancontact, Wero and Scalapay configuration types |
| `Gateway\Validator\Constraints\IsScalapayAmountRangeValid` and its validator | `Ppro\PproConfigurationValidator`, run by `PaymentMethodTypeExtension` before the save |
| `ScalapayGatewayFactory::MIN_AMOUNT`, `ScalapayGatewayFactory::MAX_AMOUNT` | **deprecated**, no longer read; removal planned with the next major |
| Twig hook `amount_range` and `admin/payment_method/form/scalapay_amount_range.html.twig` | hook `ppro_eligibility`, `admin/payment_method/form/ppro_eligibility.html.twig` |
| Translation keys `ui.scalapay_gateway_config.*` (messages), `payplug_scalapay.min_amount_greater_than_max` and `payplug_scalapay.amount_out_of_authorized_range` (validators) | `ui.ppro.*` (messages), `ppro.*` (validators) |
| `SupportedMethodsProvider::__construct()` | now also takes a `PproEligibilityChecker` |
| `CapturePaymentRequestHandler::__construct()` | now also takes the PPRO refresher, the refusal classifier, `sylius_shop.provider.order_pay.final_url` and the request stack |
| `UnifiedAuthenticationController::__construct()`, `PostSavePaymentMethodEventListener::__construct()` | now also take a `PproConfigurationRefresherInterface` |

The admin form uses a new Stimulus controller, `@payplug/sylius-payplug-plugin/ppro-eligibility`
(declared in `assets/package.json` and `assets/controllers.json`): rebuild the admin assets after the
upgrade.

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
