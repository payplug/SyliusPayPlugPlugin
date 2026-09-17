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

Requires `payplug/unified-plugin-core ^1.1.2` (for the nullable `TokenOutput::$idToken`).

> [!NOTE]
> A gateway connected before this release shows a "re-authenticate" placeholder instead of the
> account email until the merchant reconnects — the address is only available from the interactive
> OAuth `id_token`, which is minted at login.

Please refer to [github releases](https://github.com/payplug/SyliusPayPlugPlugin/releases) for historical release information.

---

For migration guides and upgrade instructions, see [UPGRADE.md](UPGRADE.md).
For contributing guidelines, see [CONTRIBUTING.md](CONTRIBUTING.md).
