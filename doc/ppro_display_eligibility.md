# PPRO display eligibility (Bancontact, Wero, Scalapay)

Bancontact, Wero and Scalapay are shown at checkout only when the cart amount and the customer's
billing country are accepted by PayPlug for that payment method, and by the merchant's own settings.

> [!NOTE]
> Oney, Apple Pay, American Express and the card payment method are not concerned.

## Merchant settings

On the payment method page (**Configuration > Payment methods**, edit Bancontact, Wero or Scalapay),
two blocks appear once the payment method is connected to a PayPlug account:

- **Cart amount**: a minimum and a maximum, in euros. The payment method is displayed only if the
  cart amount is between these two values. Both values must stay within the limits PayPlug authorizes
  for the payment method, shown under the block title and as the placeholder of each field. Leaving a
  field empty uses the PayPlug limit, including when PayPlug changes it later.
- **Billing countries**: the countries the payment method is displayed for, among the countries
  PayPlug allows for it. The list can be searched, and selected countries are shown as tags. At least
  one country must stay selected.

Errors (an amount outside the PayPlug limits, a minimum above the maximum, no country selected) are
shown while typing, and the save is refused with the same message.

### Defaults

- Every country allowed by PayPlug is selected. When every country stays selected, countries PayPlug
  allows later are included automatically.
- The minimum and maximum are empty, which means the PayPlug limits.

## When the PayPlug limits are refreshed

The PayPlug limits are stored with the payment method. They are refreshed:

- when the payment method page is opened in the admin, unless they were refreshed less than 5 minutes
  ago;
- after connecting (or reconnecting) the payment method to PayPlug;
- after a save that switches the LIVE mode on or off;
- when PayPlug refuses a payment for its billing country or its amount (same 5-minute rule);
- with the console command `bin/console payplug:ppro:refresh`, which refreshes every Bancontact,
  Wero and Scalapay payment method connected to PayPlug (run it once after upgrading the plugin);
  payment methods not connected yet are reported as skipped.

Once the limits are stored, the checkout no longer calls PayPlug to decide whether to show these
payment methods. Before that (right after the upgrade, or after a logout from PayPlug), and while
the stored limits belong to the other environment (TEST vs LIVE), they are filtered live from the
PayPlug account, as before the upgrade, and by the merchant settings already saved.

An empty minimum or maximum, like a selection of every country, follows PayPlug's changes. A value
the merchant entered is kept; when PayPlug narrows its limits below it, it is adjusted at the next
refresh and a notice is shown on the payment method page. When none of the selected countries is
allowed by PayPlug any more, the payment method is hidden at checkout, never shown more widely than
the merchant chose, until new countries are selected; the payment method page shows a warning.

If PayPlug reports the payment method as not enabled on the account, it is hidden at checkout and a
warning is shown on the payment method page, as of the last refresh. The payment method itself stays enabled in Sylius.
PayPlug may enable it later: 24 hours after the last refresh, the method is shown again and PayPlug
decides at payment time.

A Scalapay range set before the upgrade keeps narrowing the checkout, and becomes the merchant range
when the limits are first stored, unless a range was entered in the meantime.

Logging out from PayPlug clears the stored PayPlug limits but keeps the merchant range and countries.

## When PayPlug cannot be reached

- On the payment method page: the last stored limits are shown, with a warning.
- At checkout: nothing changes, the stored limits are used.
- If no limits have ever been stored (payment method never refreshed since the upgrade), Bancontact,
  Wero and Scalapay are filtered live from the PayPlug account; if it cannot be read either, they are
  shown for every amount and country, and PayPlug accepts or refuses the payment.

The checkout is never blocked by this feature.

## What the customer sees after a refusal

If PayPlug refuses a payment because of the billing country or the amount, the customer is sent back
to the order page with the message "This payment method is not available for your billing country or
for this amount. Please choose another payment method." The stored limits are refreshed at the same
time, so the payment method is no longer offered where PayPlug does not accept it.

For a headless frontend, the payment request response data carries `redirect_url` (the order page)
and `error` (the translation key `payplug_sylius_payplug_plugin.error.ppro_not_available`). No flash
message is added for a JSON or XHR request.

## Adding a PPRO payment method

The list of PPRO payment methods is `PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods`. Once the
gateway of a new PPRO payment method exists (gateway factory, configuration type, resolver decorator,
refund provider, translations), adding it means one line in `PproPaymentMethods::API_KEY_BY_FACTORY`,
its configuration type in `PproEligibilityTypeExtension::getExtendedTypes()` and the
`ppro_eligibility` hook entries in `config/twig_hooks/admin.yaml`.
