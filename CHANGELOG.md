# Changelog

All notable changes to the Freepay Payment Plugin for Shopware 6.

## [1.1.1] - 2026-06-24

### Fixed
- Plugin install/update no longer fails with `SQLSTATE[23000] ... 1062 Duplicate entry
  'freepay_payment_identifier' for key 'uniq.custom_field.name'`. The custom field set is
  now upserted with stable IDs resolved by name, so reinstalling on a database that still
  holds the freepay custom fields (e.g. after an uninstall that kept user data) reuses the
  existing rows instead of trying to insert duplicates.

## [1.1.0] - 2026-06-23

### Added
- Partial captures: a "Freepay capture" card on the order Details tab lets merchants
  capture the authorization in multiple steps, showing the captured and remaining
  amounts. Backed by `POST /api/_action/freepay/capture/{orderId}` and
  `GET /api/_action/freepay/captures/{orderId}`.

### Fixed
- Capture records now store the actual captured amount instead of the full order total
  (previously the capture-on-shipment path recorded the full transaction amount even for
  partial shipments, inflating the refundable total).
- Capturing is now idempotent: flipping an order to "Paid" after a manual capture no longer
  double-captures, and `paid_partially → paid` captures only the outstanding remainder.

## [1.0.0] - 2026-03-02

### Added
- Initial release of Freepay Payment Plugin

### Technical
- Compatible with Shopware 6.7.0
- PHP 8.1+ support
- REST API integration
