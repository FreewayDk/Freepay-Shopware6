# Freepay Payment Plugin for Shopware 6

A Shopware 6 payment plugin that integrates Freepay Payment Service Provider with support for external payment windows, webhook notifications, and return URL handling.

## Features

- **Async Payment Handler**: Redirects customers to Freepay's hosted payment page
- **Webhook Support**: Processes asynchronous payment status notifications from Freepay
- **Idempotent Webhook Processing**: Prevents duplicate state changes from repeated webhooks
- **Multiple Payment States**: Supports authorized, paid, pending, failed, cancelled, and refunded states
- **Signature Verification**: Validates webhook authenticity using HMAC-SHA256
- **Sandbox Mode**: Test integration without processing real payments
- **Configurable**: Flexible settings for API endpoints, credentials, and behavior
- **Logging**: Debug mode for tracking API requests and webhook events
- **Capture & Refund**: Support for manual captures and refunds via API
- **Multi-language**: English and Danish language support

## Requirements

- Shopware 6.5.0 or higher
- PHP 8.1 or higher
- Freepay merchant account with API credentials

## Installation

See [INSTALL.md](INSTALL.md) for quick start guide.

### Via Composer (Recommended)

```bash
composer require custom/freepay-payment
bin/console pluginrefresh
bin/console plugin:install --activate FreepayPayment
bin/console cache:clear
```

### Manual Installation

1. Download the plugin
2. Extract to `custom/plugins/FreepayPayment/`
3. Install and activate:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate FreepayPayment
bin/console cache:clear
```

## Configuration

Navigate to: **Settings → System → Plugins → Freepay Payment → Configuration**

### Required Settings
- Merchant ID
- API Key
- Webhook Secret
- Sandbox Mode (enable for testing)

### Webhook URL
Configure this URL in your Freepay merchant dashboard:
```
https://your-shop-domain.com/freepay/webhook
```

## Documentation

- [Installation Guide](INSTALL.md) - Quick start guide
- [Changelog](CHANGELOG.md) - Version history

## License

MIT License - See LICENSE file for details
