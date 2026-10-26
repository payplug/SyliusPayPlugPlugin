# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Added
- Support for Sylius v2.0+
- PHP 8.2+ compatibility
- Use Payment Request API from Sylius
- New Unified Authentication System (OAuth2)
- **Multi-shop support**: several gateway configurations of the same type may now coexist, each
  connected to its own PayPlug account and scoped to its own channels
- The connected PayPlug account is displayed on each gateway's update screen
- "Disconnect this account" per gateway, clearing that gateway's credentials without touching others
- Channels already claimed by another enabled gateway of the same type are rendered unselectable
- **Hosted Fields deferred capture**: with deferred capture enabled, Hosted Fields payments are
  created as authorizations and captured (in full, partially, several times) or cancelled (in full
  or partially) from the admin order screen, with the remaining amount and capture deadline shown
- The Hosted Fields JS SDK URL is configurable through `PAYPLUG_HOSTED_FIELDS_SDK_URL` and defaults
  to the production SDK (`v2.2.0`): QA, staging and local setups must set it to their own SDK URL
- **PPRO display eligibility** for Bancontact, Wero and Scalapay: each method is shown at checkout
  only for the cart amounts and billing countries PayPlug accepts, and the merchant can narrow both
  from the payment method page (minimum/maximum in euros inside the PayPlug limits, billing
  countries among those PayPlug allows), with live validation in the admin form
- `payplug:ppro:refresh` console command, to store the PayPlug limits of every PPRO payment method
  once after the upgrade
- Hosted Fields card form: a cardholder name field above the card number (5 to 45 characters, checked
  in the browser and on the server), sent to PayPlug as the card's full name
- Hosted Fields card form: the network selector is shown inside the card number field; for a
  co-branded card each network is shown side by side, the preselected one carries a checkmark and the
  shopper switches with one click
- Hosted Fields card form: each field shows a green border when valid, and a red border with a message
  under it when invalid

> [!IMPORTANT]
> Merchants will need to contact support to switch to the new authentication method.

### Changed
- Plugin structure has been changed to follow the new Symfony bundle structure
- Front assets have been migrated to use Stimulus
- Gateway uniqueness is now enforced **per channel** instead of per installation: two gateways of
  the same type may both be enabled as long as their channel sets are disjoint
- Credentials are resolved from the payment method rather than from the gateway factory name, so a
  request for one channel can no longer be signed with another channel's account
- Oney refunds are allowed as soon as the PayPlug API reports the payment as refundable
  (`refundable_after` / `refundable_until`); outside that window the back-office message states the
  date and time, with its timezone, from which the refund is possible or at which the refund period
  ended
- The PayPlug amount and country limits of Bancontact, Wero and Scalapay are stored per payment
  method and refreshed when the admin page is opened, after an OAuth connection, after a save that
  switches the LIVE mode and after a payment PayPlug refuses for its country or amount (the admin page
  and a refusal reuse limits refreshed less than 5 minutes ago): once they are stored, the checkout no
  longer calls the PayPlug API for these methods, which are filtered live from the account and by the
  merchant settings until then (or while the stored limits belong to the other LIVE/TEST environment)
- A PPRO method PayPlug reports as not enabled is hidden for 24 hours after the last refresh, then
  shown again for PayPlug to decide at payment time
- When PayPlug no longer allows any of the billing countries the merchant selected, the PPRO method
  is hidden until new countries are selected, instead of being shown for every allowed country
- A logout from PayPlug clears the stored PayPlug limits of a PPRO method (the merchant range and
  countries are kept), and a warning is shown when they cannot be captured after a connection
- A Bancontact, Wero or Scalapay payment refused by PayPlug for its billing country or amount sends
  the customer back to the order page with a message to choose another payment method
- A PPRO amount outside the PayPlug limits, or an empty country selection, is refused when saving
  the payment method instead of being saved with the payment method disabled
- The Scalapay minimum/maximum amount override keeps narrowing the checkout until the PayPlug limits
  are first stored, then becomes the merchant range of the new PPRO settings, adjusted to them if
  needed (see [UPGRADE.md](UPGRADE.md))
- Hosted Fields card form: the text typed in the card, expiry and CVV fields is vertically centred and
  uses the same font as the cardholder name field; the static Visa/MC badges above the card field are
  removed
- Hosted Fields card form: the "select your network" label of co-branded cards sits under the card
  field, with its space reserved, instead of overlapping the next fields; an invalid card number
  message is shown on its own line under that label
- The Hosted Fields card network and cardholder name are checked on the server: only CB, VISA and
  MASTERCARD are accepted, the name is composed (NFC) and trimmed like in the browser (Unicode spaces
  included) and must not contain control characters, and a submission that fails these checks sends
  the shopper back to the payment step with an error message (a 400 for a non-HTML request) before the
  Hosted Fields details are stored or the checkout is completed

### Removed
- Drop Payum support
- Drop Sylius 1.x support
- Drop usage of Secret key - Use OAuth2 instead
- The fixed 48h waiting time on Oney refunds, counted from the last payment or refund
- Scalapay-only minimum/maximum amount fields and their save-time constraint, replaced by the PPRO
  settings shared by Bancontact, Wero and Scalapay

### Fixed
- Integrated Payment no longer accepts a payment method id that does not belong to the order's
  channel, or a disabled one — previously a shopper could have the payment created on another
  merchant's PayPlug account
- Oney instalment options, the Oney availability check and Apple Pay now resolve the gateway serving
  the current channel instead of an arbitrary one
- Hosted Fields payments, with a new or a saved card, are accepted again by the Unified API, which
  now reads the card token from `paymentMethod.hfToken` and the saved card from
  `paymentMethod.storedId`
- Cards the shopper asked to save are stored again, including after a 3DS challenge (the alias is
  read from `paymentMethod.storedId`)
- Refunding a Hosted Fields payment that PayPlug created without a payment reference is refused
  with a clear back-office message, in addition to Sylius's own generic error message, and nothing
  is sent to PayPlug; the payment itself is processed normally
- Hosted Fields deferred capture and cancel keep working with `payplug/unified-plugin-core` 1.2.1:
  the plugin now passes the payment currency to UPC's `AmountHelper`, which requires it since
  PRE-3724
- A PayPlug account that cannot be read (expired credentials, network error) no longer breaks the
  payment method list at checkout: the Apple Pay, American Express and card methods are kept and the
  error is logged
- Hosted Fields iframes are only mounted once the payment method's form is displayed, so they no
  longer render broken when the method is preselected or chosen before its form is shown

### Breaking changes for anyone extending the plugin

| Removed / changed | Replacement |
| --- | --- |
| `PayPlugApiClientFactoryInterface::create(string $factoryName)` | `createForPaymentMethod(PaymentMethodInterface $pm)` |
| `UnifiedApiPaymentCreatorInterface::createPayment($dto)` | `createPayment($dto, PaymentMethodInterface $method)` |
| `OperationStatusFetcherInterface::getOperation($id)` | `getOperation($id, PaymentMethodInterface $method)` |
| `AbstractGatewayConfigurationType::__construct()` — `$gatewayConfigRepository` and `$requestStack` dropped | translator only |
| `shouldValidateBaseCurrency()` / `baseCurrencyViolationMessage()` — `protected` → `public`, now take the **mapped** config | same hooks, new visibility/shape |
| `$gatewayFactoryName` property on the 8 configuration types | no longer read; the factory name comes off the gateway config |
| Translation key `form.only_one_gateway_allowed` | `form.gateway_channel_conflict` (`%channel%`, `%payment_method%`) |
| Injecting `PayplugUnifiedCore\Contracts\IConfigurationRepository` (its service alias is gone) | `ScopedConfigurationRepositoryInterface`, scoped per payment method |
| `PaymentMethodRepositoryInterface::findOneByGatewayName()` — **deprecated**, returns an arbitrary config when several share a factory name | `findOneEnabledByGatewayNameAndChannel($factoryName, $channel)` |
| `OneyExtension::__construct()` — `$gatewayConfigRepository` dropped, `$paymentMethodRepository` is now the plugin's `PaymentMethodRepositoryInterface` | inject the plugin repository |
| `OneySupportedPaymentChoiceProvider::__construct()` | now also takes a `ChannelContextInterface` |
| `PayPlugExtension::__construct()` | now also takes `string $hostedFieldsSdkUrl` (bound to `%payplug.hosted_fields_sdk_url%`) |
| `OneyGatewayFactory::REFUND_WAIT_TIME_IN_HOURS` | none, the PayPlug API refund window is the only rule |
| Translation key `ui.oney_transaction_less_than_forty_eight_hours` | `ui.oney_refund_not_available_before` / `ui.oney_refund_period_expired` (`%date%`) |
| `RefundPaymentGeneratedHandler::__construct()` — `$orderRepository` and `$translator` dropped | container-built, no replacement needed |
| `ScalapayGatewayConfigurationTypeExtension`, `IsScalapayAmountRangeValid` and its validator | `PproEligibilityTypeExtension`, `Ppro\PproConfigurationValidator` |
| `ScalapayGatewayFactory::MIN_AMOUNT` / `MAX_AMOUNT` — **deprecated**, no longer read | `payment_method.configured_amount` in the gateway config |
| `SupportedMethodsProvider`, `CapturePaymentRequestHandler`, `UnifiedAuthenticationController`, `PostSavePaymentMethodEventListener` constructors | new PPRO collaborators, see [UPGRADE.md](UPGRADE.md) |
| `HostedFieldsCaptureData::__construct()` — now `($hfToken, $selectedBrand, $saveCard, HostedFieldsCardDetails $card, $cardHolderName)`; the brand and the name are validated and throw `InvalidHostedFieldsSubmissionException` | pass the card metadata as a `HostedFieldsCardDetails` and the cardholder name |
| `HostedFieldsCaptureData::$last4`, `$expirationMonth`, `$expirationYear`, `$countryCode` | `$card->last4`, `$card->expirationMonth`, `$card->expirationYear`, `$card->countryCode` |
| `PostPaymentSelectEventSubscriber::__construct()` | now also takes a `LoggerInterface` (container-built) |
| Translation keys `ui.hosted_fields.network_selection.label` / `ui.hosted_fields.network_selection.tooltip` | none, the label is the SDK's own, localised through the `locale` option |

> [!WARNING]
> Themes overriding `@PayPlugSyliusPayPlugPlugin/shop/hosted_fields/index.html.twig` must add the
> cardholder name input (Stimulus target `holder`) and the hidden `hostedfields_card_holder_name`
> input: a Hosted Fields checkout posted without a valid cardholder name is sent back to the
> payment step with an error message.

Requires `payplug/unified-plugin-core ^1.2.1` (for the nullable `TokenOutput::$idToken` and the
Unified API payment contract: `paymentMethod.hfToken`, `paymentMethod.storedId`).

> [!NOTE]
> A gateway connected before this release shows a "re-authenticate" placeholder instead of the
> account email until the merchant reconnects — the address is only available from the interactive
> OAuth `id_token`, which is minted at login.

Please refer to [github releases](https://github.com/payplug/SyliusPayPlugPlugin/releases) for historical release information.

---

For migration guides and upgrade instructions, see [UPGRADE.md](UPGRADE.md).
For contributing guidelines, see [CONTRIBUTING.md](CONTRIBUTING.md).
