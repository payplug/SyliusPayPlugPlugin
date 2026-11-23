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
PayPlug limits are stored in each payment method's gateway config (`payment_method` key); once
stored, the checkout no longer reads the PayPlug API for these methods.

What changes for a merchant already in production, in this order:

1. **The Scalapay minimum/maximum amount override is carried over.** Until the PayPlug limits are
   first captured, the former `min_amount` and `max_amount` keys of a Scalapay gateway config keep
   narrowing the checkout. At the first capture they become the merchant range, adjusted to the
   PayPlug limits if they no longer fit (the payment method page then shows a notice), unless the
   merchant entered a range in the meantime. They are not read again afterwards.
2. **Until the PayPlug limits are first captured, these methods are filtered live from the PayPlug
   account**, by amount and billing country, as before the upgrade, and by the merchant settings
   already saved (including the former Scalapay range). The limits are captured when the payment method page is opened in the
   admin, after an OAuth (re)connection, after a save that switches the LIVE mode, or after a payment
   refused by PayPlug for its country or amount. To capture them right after the upgrade, run once:

   ```bash
   bin/console payplug:ppro:refresh
   ```

   Payment methods not connected to a PayPlug account yet are reported as skipped. The command exits
   with a non-zero code if a connected payment method could not be refreshed (see the `payplug` log
   channel); run it again once PayPlug is reachable.
3. Every country allowed by PayPlug is accepted by default, and the merchant range is empty, which
   means "the PayPlug limits" (shown as the placeholder of the minimum and maximum fields). At least one country must stay selected, and an amount outside the PayPlug limits
   is refused when saving (the payment method is no longer saved and disabled instead).
4. **Fallback.** Without stored limits, with stored limits that cannot be read, or with limits fetched
   in the other environment (TEST vs LIVE, when the refresh after a switch failed), the payment
   method is filtered live from the PayPlug account and by the merchant settings; if the account
   cannot be read either, it is
   shown and PayPlug arbitrates at payment time: this feature never blocks the checkout. When
   PayPlug refuses a payment for its billing country or amount, the limits are refreshed and the
   customer is sent back to the order page to choose another payment method; the payment request
   response data carries `redirect_url` and `error` for headless frontends.
5. The stored limits move only on the events listed in point 2, not on a schedule. The admin page and
   a refused payment reuse limits refreshed less than 5 minutes ago instead of calling PayPlug again.
   An empty minimum or maximum, like a selection of every country, follows PayPlug's changes. A value
   the merchant entered is kept, and only adjusted when PayPlug narrows its limits below it. When none
   of the merchant's countries is allowed any more, the method is hidden until new countries are
   selected (a warning is shown on the payment method page). A method
   PayPlug reports as not enabled is hidden for 24 hours after the last refresh; past that, it is
   shown again and PayPlug decides at payment time.
6. A logout from PayPlug clears the stored PayPlug limits (the merchant range and countries are
   kept), so a reconnection to another PayPlug account never filters with the previous account's
   limits. If the limits cannot be captured right after a connection, a warning is shown.

For anyone extending the plugin:

| Removed / changed | Replacement |
| --- | --- |
| `Gateway\Form\Extension\ScalapayGatewayConfigurationTypeExtension` | `Gateway\Form\Extension\PproEligibilityTypeExtension`, on the Bancontact, Wero and Scalapay configuration types |
| `Gateway\Validator\Constraints\IsScalapayAmountRangeValid` and its validator | `Ppro\PproConfigurationValidator`, run by `PaymentMethodTypeExtension` before the save |
| `ScalapayGatewayFactory::MIN_AMOUNT`, `ScalapayGatewayFactory::MAX_AMOUNT` | **deprecated**, read once to carry the range over; removal planned with the next major |
| Twig hook `amount_range` and `admin/payment_method/form/scalapay_amount_range.html.twig` | hook `ppro_eligibility`, `admin/payment_method/form/ppro_eligibility.html.twig` |
| Translation keys `ui.scalapay_gateway_config.*` (messages), `payplug_scalapay.min_amount_greater_than_max` and `payplug_scalapay.amount_out_of_authorized_range` (validators) | `ui.ppro.*` (messages), `ppro.*` (validators) |
| `SupportedMethodsProvider::__construct()` | now also takes a `PproEligibilityChecker` and an `AccountAllowedCountriesResolver` |
| `PproConfigurationRefresherInterface` | `refreshIfStale()` added next to `refresh()` |
| `CapturePaymentRequestHandler::__construct()` | now also takes the PPRO refresher, the refusal classifier, `sylius_shop.provider.order_pay.final_url` and the request stack |
| `UnifiedAuthenticationController::__construct()`, `PostSavePaymentMethodEventListener::__construct()` | now also take a `PproConfigurationRefresherInterface` |

The admin form uses a new Stimulus controller, `@payplug/sylius-payplug-plugin/ppro-eligibility`
(declared in `assets/package.json` and `assets/controllers.json`). If your application lists the
plugin's controllers in its own admin `assets/admin/controllers.json`, add it there, otherwise the
live validation of the form never runs (the save is still validated on the server):

```json
{
    "controllers": {
        "@payplug/sylius-payplug-plugin": {
            "ppro-eligibility": {
                "enabled": true,
                "fetch": "lazy"
            }
        }
    }
}
```

Then rebuild the admin assets.

# Scalapay widget (PRE-3606)

The Scalapay widget (v5, the version compliant with the consumer credit rules applying from
20 November 2026) can be shown on the product page, the cart page and under the Scalapay payment
method at checkout, once the merchant turns it on. It needs the Scalapay merchant token of the PayPlug account, which
PayPlug returns with the Scalapay limits and which is stored with them (`payment_method.merchant_token` in the gateway
config).

1. **A Scalapay payment method saved before the upgrade has no token until its limits are refreshed**,
   and shows no widget until then. To store it right after the upgrade, run once:

   ```bash
   bin/console payplug:ppro:refresh
   ```

   It is also stored on the events that refresh the PayPlug limits (see the PPRO section above). The
   widget is not shown while PayPlug returns no token, and a warning is logged on the `payplug` channel
   at each refresh.
2. The widget is **off** on the three pages by default: nothing changes on an existing shop until the
   merchant turns a page on. Each page can be turned on, and the CSS selector of the amount the
   widget reads can be changed, in the **Scalapay widget** block of the Scalapay
   payment method page (gateway config key `scalapay_widget`). The defaults match the Sylius 2
   default shop theme; **a custom theme that displays the price elsewhere must set its own
   selectors**:

   | Page | Default amount selector |
   | --- | --- |
   | Product | `[data-live-name-value="sylius_shop:product:summary"] .fs-3` |
   | Cart | `[data-live-name-value="sylius_shop:cart:summary"] .h5.text-end` |
   | Checkout | `#sylius-shop-checkout-summary-order-total` |

3. The version 5 widget script of Scalapay (`templates/shop/scalapay/script.html.twig`) is loaded
   once, in the `sylius_shop.base.head` hook, on these three pages only. A shop layout that does not
   render the `head` hook of `@SyliusShop/shared/layout/base.html.twig` must load it itself, and a
   Content Security Policy must allow the host that script is served from.
4. The widget is rendered with `environment="integration"` when the plugin is connected to a PayPlug
   platform other than production (`PAYPLUG_OAUTH_BASE_URL` set to anything but the production
   platform URL, which is its default value), in TEST and LIVE mode alike, and without the
   attribute on the production platform. A QA setup must set `PAYPLUG_OAUTH_BASE_URL` to the OAuth
   URL of the QA platform.

For anyone extending the plugin:

| Added / changed | Notes |
| --- | --- |
| `Ppro\PproConfig::__construct()` | new optional last argument `?string $merchantToken = null`; `toArray()` adds `merchant_token` when it is known |
| `Auth\GatewayConnectionRevoker` | a logout also clears the stored merchant token |
| `Scalapay\ScalapayWidgetResolver`, `Scalapay\ScalapayWidgetSettings`, `Scalapay\ScalapayWidgetEnvironment`, `Twig\ScalapayWidgetExtension` | Twig functions `payplug_scalapay_product_widget()`, `payplug_scalapay_cart_widget()`, `payplug_scalapay_checkout_widget()`, `payplug_scalapay_widget_script_required()` |
| `Gateway\Form\Extension\ScalapayWidgetTypeExtension` | fields `scalapay_widget_<page>` and `scalapay_widget_<page>_amount_selector` on the Scalapay configuration type, admin hook `scalapay_widget` |
| Shop Twig hooks | `sylius_shop.base.head` (`payplug_scalapay_widget_script`), `pay_with_scalapay` on the product summary and cart hooks, `scalapay_widget` on `sylius_shop.shared.form.select_payment.payment.choice.payplug_scalapay` |
| `templates/shared/form/select_payment/payment/choice.html.twig` | renders, for each payment method, the hookables of `sylius_shop.shared.form.select_payment.payment.choice.<factory_name>` together with those of `choice`, ordered by priority (the Scalapay widget, priority -100, comes after the payment card, `details` at 0), and adds `parent_form` to the `form` and `method` context. This is what the Sylius template does from 2.0.8; on Sylius 2.0.0 to 2.0.7 it is an addition |
| Hooks of your own on `sylius_shop.shared.form.select_payment.payment.choice.<factory_name>` | are now rendered by the plugin template, on every supported Sylius version |

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
