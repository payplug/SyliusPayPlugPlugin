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

> [!IMPORTANT]
> Merchants will need to contact support to switch to the new authentication method.

### Changed
- Plugin structure has been changed to follow the new Symfony bundle structure
- Front assets have been migrated to use Stimulus

### Removed
- Drop Payum support
- Drop Sylius 1.x support
- Drop usage of Secret key - Use OAuth2 instead

Please refer to [github releases](https://github.com/payplug/SyliusPayPlugPlugin/releases) for historical release information.

---

For migration guides and upgrade instructions, see [UPGRADE.md](UPGRADE.md).
For contributing guidelines, see [CONTRIBUTING.md](CONTRIBUTING.md).
