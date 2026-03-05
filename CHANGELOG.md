# Changelog

All notable changes to the Freepay Payment Plugin for Shopware 6.

## [1.0.0] - 2026-03-02

### Added
- Initial release of Freepay Payment Plugin
- Async payment handler with external payment window
- Webhook controller for PSP callbacks
- Return URL controllers (success/cancel/error)
- API client for Freepay integration
- Payment session creation
- Payment status retrieval
- Capture and refund support
- Webhook signature verification (HMAC-SHA256)
- Idempotent webhook processing
- Multiple payment state support
- Configurable sandbox/production mode
- Debug logging
- Multi-language support (English/Danish)
- Complete documentation

### Features
- Redirect-based payment flow
- Real-time webhook notifications
- Order transaction state management
- Secure signature verification
- Customer data transmission
- Error handling with customer-friendly messages

### Technical
- Compatible with Shopware 6.5.0 and 6.6.0
- PHP 8.1+ support
- REST API integration
- Symfony service container
