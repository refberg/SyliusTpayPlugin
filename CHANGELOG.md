# Changelog

All notable changes to this project are documented in this file.
Versioning follows [Semantic Versioning](https://semver.org/): deprecations are added in 1.x minor releases,
deprecated code is removed in 2.0.

## Unreleased

### Added

- Tpay OpenAPI gateway (`refberg_tpay`) built on Sylius Payment Requests, without Payum.
- Shop and API checkout with redirect to the Tpay payment page; `continue_url` payload for API clients.
- Status check after the customer returns, based on the Tpay API; thank-you page for a pending transaction
  when the customer comes back through the success URL.
- Notification endpoint: `md5sum` and `X-JWS-Signature` verification (in the notify payment provider and again
  in the handler), merchant, transaction, `hiddenDescription`, amount and currency checks; idempotent and
  order-independent; `TRUE` response, HTTP 400/404/503 and 5xx for retries.
- Refunds from the admin panel: full refund, or the amount not yet refunded in the Tpay panel.
- Refunds made in the Tpay panel are recorded as external refunds; a full one marks the payment as refunded.
- Cancelling a payment in Sylius cancels pending Tpay transactions.
- Restoring the paid Tpay method when the customer switched the payment method after the redirect
  (`paid_with_previous_payment_method` warning).
- Applications without the Sylius shop, admin panel or Twig Hooks.
- `refberg_tpay.http_timeout` container parameter, `refberg_tpay.http_client` and `refberg_tpay.cache` services.
- Support for Sylius 2.2.12+ and 2.3.2+, PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.x.

### Changed

- Nothing yet.

### Deprecated

- Nothing yet.

### Fixed

- Nothing yet.

### Removed

- Nothing yet.
