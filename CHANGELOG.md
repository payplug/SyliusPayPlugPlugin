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

> [!IMPORTANT]
> Merchants will need to contact support to switch to the new authentication method.

### Changed
- Plugin structure has been changed to follow the new Symfony bundle structure
- Front assets have been migrated to use Stimulus
- Gateway uniqueness is now enforced **per channel** instead of per installation: two gateways of
  the same type may both be enabled as long as their channel sets are disjoint
- Credentials are resolved from the payment method rather than from the gateway factory name, so a
  request for one channel can no longer be signed with another channel's account

### Removed
- Drop Payum support
- Drop Sylius 1.x support
- Drop usage of Secret key - Use OAuth2 instead

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
