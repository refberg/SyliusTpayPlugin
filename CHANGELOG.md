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
- When Sylius cancels the capture payment request of a Tpay transaction that is still `pending` (e.g. the customer
  switched the payment method), the plugin asks Tpay to cancel that transaction. The result is only logged;
  a refusal from Tpay is logged as `critical`, because the transaction may have been paid.
- Applications without the Sylius shop, admin panel or Twig Hooks.
- `refberg_tpay.http_timeout` container parameter, `refberg_tpay.http_client` and `refberg_tpay.cache` services.
- Support for Sylius 2.2.12+ and 2.3.2+, PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.x.

### Changed

- A Tpay transaction paid after the Sylius payment was already completed by another transaction, failed or cancelled
  is logged as `critical` instead of `error`. The warning names in the payment details are unchanged.
- New optional last constructor arguments: `PaymentStateApplier` (`CredentialsResolverInterface`,
  `PaymentRequestRepositoryInterface`), `NotifyPaymentRequestHandler` and `StatusPaymentRequestHandler` (`PaymentRequestRepositoryInterface`).

### Deprecated

- Nothing yet.

### Fixed

- The capture payment request is completed or failed when the notification or the status check finds its Tpay
  transaction paid, refunded or canceled (the status check also closes capture requests left processing for Tpay transactions
  that were already finished), so paying again after a canceled Tpay transaction creates a new Tpay transaction
  instead of reusing the dead payment page.
- A canceled Tpay transaction no longer fails a payment that was switched to another payment method (outside Tpay
  or another Tpay method, also when the customer later switched back) once its capture request was cancelled.

### Removed

- Nothing yet.
