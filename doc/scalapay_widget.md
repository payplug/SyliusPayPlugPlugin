# Scalapay widget

The Scalapay widget shows the customer the Scalapay installment plans for the price on the page. It
is served by Scalapay (widget v5) and displayed as provided: the plugin adds no text, style or script
around it.

| Page | Where | Amount read |
| --- | --- | --- |
| Product | Under the price, above the add-to-cart form | Price of the selected variant |
| Cart | Under the cart summary, above the checkout button | Order total |
| Checkout | Right under the Scalapay payment method card, outside it, visible before the method is selected | Order total |

## When it is shown

All the following must hold:

- a Scalapay payment method is enabled on the current channel;
- PayPlug returned a Scalapay merchant token for the account, in the mode (TEST or LIVE) the payment
  method is in. The token is stored with the PayPlug limits of the method (see
  [PPRO display eligibility](ppro_display_eligibility.md)): nothing is entered by the merchant and
  PayPlug is not called when a page is displayed;
- the page is turned on in the **Scalapay widget** block of the Scalapay payment method page (the
  widget is off on every page until the merchant turns it on);
- the channel base currency is the euro and prices are displayed in euros;
- the amount is within the Scalapay range of the payment method (PayPlug limits narrowed by the
  merchant), and the billing country, when the cart has one, is accepted. The product and cart widgets
  follow a variant, quantity or coupon change.

## Amount selectors

The widget reads the amount from the element matching the CSS selector set for each page. The
defaults match the Sylius 2 default shop theme:

| Page | Default |
| --- | --- |
| Product | `[data-live-name-value="sylius_shop:product:summary"] .fs-3` |
| Cart | `[data-live-name-value="sylius_shop:cart:summary"] .h5.text-end` |
| Checkout | `#sylius-shop-checkout-summary-order-total` |

With another theme, set the selector of the element that shows the price, and check it in the
browser console with `document.querySelector('<selector>')`. A selector can use ids, classes,
attributes, combinators, pseudo-classes and selector lists; markup, `{`, `}`, `;`, `\` and `/` are
refused.

## Language and environment

- `locale` is the shop language when Scalapay supports it (`en`, `it`, `fr`, `es`, `de`, `pt`),
  English otherwise.
- `amount-separator` is the decimal separator of the prices Sylius prints for the shop locale.
- `environment` follows the PayPlug platform the plugin is connected to, in TEST and LIVE mode alike:
  the merchant token PayPlug returns is a Scalapay production token on the PayPlug production
  platform and a Scalapay integration token on any other platform. The platform is read from
  `%payplug.oauth_base_url%` (environment variable `PAYPLUG_OAUTH_BASE_URL`, whose default value
  is the production platform of PayPlug):

  | `PAYPLUG_OAUTH_BASE_URL` | `environment` attribute |
  | --- | --- |
  | not set, or the production platform URL (case and trailing slash ignored) | left out |
  | any other URL | `environment="integration"` |

  A shop connected to the QA platform of PayPlug must set `PAYPLUG_OAUTH_BASE_URL` to the OAuth URL
  of that platform, which the PayPlug login needs anyway; otherwise the widget is rendered for the
  Scalapay production environment and finds no configuration for the QA token.

## Checking a page

In the browser inspector, the page has one `<script type="module">` in `<head>` that loads the
version 5 widget script of Scalapay, and a `<scalapay-widget>` element with a non-empty `merchant-token`. When nothing appears, the
widget explains why in the browser console (for example, a selector matching no element).
