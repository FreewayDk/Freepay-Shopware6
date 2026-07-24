# Freepay Payment Plugin for Shopware 6

A Shopware 6 payment plugin that integrates Freepay Payment Service Provider with support for external payment windows, webhook notifications, and return URL handling.

## Requirements

- Shopware 6.7.0 or higher
- PHP 8.1 or higher
- Freepay merchant account with API credentials

## Installation

See [INSTALL.md](INSTALL.md) for quick start guide.

### Via Composer (Recommended)

```bash
composer require freepay/shopware6
bin/console plugin:refresh
bin/console plugin:install --activate FreepayPaymentShopware6
bin/console asset:install
bin/console cache:clear
```

### Manual Installation

1. Download the plugin
2. Extract to `custom/plugins/FreepayPaymentShopware6/`
3. Install and activate:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate FreepayPaymentShopware6
bin/console asset:install
bin/console cache:clear
```

## Configuration

Navigate to: **Settings → System → Plugins → Freepay Payment → Configuration**

### Basic Configuration
- **Communication key** (required) — your Freepay API key, used to authenticate all API requests
- **Sandbox Mode** (default: on) — enable for test transactions

### Payment Settings
- **Capture on shipment** (default: off) — automatically send a capture request to Freepay whenever a shipment is created

## Documentation

- [Installation Guide](INSTALL.md) - Quick start guide
- [Changelog](CHANGELOG.md) - Version history

## License

MIT License - See LICENSE file for details
