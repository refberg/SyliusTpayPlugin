# Sylius Tpay Plugin

[![Build](https://github.com/refberg/SyliusTpayPlugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusTpayPlugin/actions/workflows/build.yaml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[Polska wersja](README.pl.md)

Tpay payment gateway for Sylius 2, built on Sylius Payment Requests and the [Tpay OpenAPI](https://openapi.tpay.com/).
It does not use Payum.

## Features

- Tpay hosted payment page (redirect) in the shop checkout and in the Sylius API. The customer picks
  the payment channel (bank transfer, BLIK, card, ...) on the Tpay page.
- Payment state is changed only by a signed Tpay notification or by a direct query to the Tpay API.
  The customer's return from Tpay is never treated as proof of payment.
- Notifications are verified twice (`md5sum` with the security code and the `X-JWS-Signature` header with the
  Tpay certificate), then the transaction is read again from the Tpay API and checked against the Sylius payment:
  transaction ID and title, merchant ID, `hiddenDescription`, amount and currency.
- Duplicated and out-of-order notifications do not change the result and never pay an order twice.
- Refunds from the admin panel; refunds made in the Tpay panel are synchronized.
- Cancelling a payment in Sylius cancels the pending Tpay transaction.
- Credentials are stored encrypted by Sylius and are not written to logs.

Out of scope: preselected payment channels (BLIK Level 0, card form on the shop page), saved cards,
recurring payments, marketplace, partial refunds started from Sylius.

## Requirements

| Sylius  | PHP     | Symfony                            |
|---------|---------|------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                           |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x needs PHP 8.4+) |

PHP extensions: `openssl` (notification signatures), `json`, `mbstring`.

The Sylius shop (`SyliusShopBundle`) and admin panel (`SyliusAdminBundle`) are optional, so the plugin also works
in API-only applications.

The `sylius_payment` state machine must use the Symfony Workflow adapter, which is the Sylius default.
The plugin sends refunds and cancellations to Tpay from listeners of the `workflow.sylius_payment.completed.refund`
and `workflow.sylius_payment.completed.cancel` events. When `sylius_payment` uses the winzou adapter
(`winzou_state_machine` in `sylius_state_machine_abstraction.graphs_to_adapters_mapping` or `default_adapter`),
refunds and cancellations made in Sylius are not sent to Tpay and have to be made in the Tpay panel.

The Tpay account must support the order currency. Without a multi-currency agreement Tpay accepts PLN only;
a transaction in another currency fails and the customer cannot pay.

## Installation

1. Install the package:

   ```bash
   composer require refberg/sylius-tpay-plugin
   ```

2. Register the bundle in `config/bundles.php`, if Symfony Flex did not do it:

   ```php
   Refberg\SyliusTpayPlugin\RefbergSyliusTpayPlugin::class => ['all' => true],
   ```

3. Import the configuration in `config/packages/refberg_sylius_tpay.yaml`:

   ```yaml
   imports:
       - { resource: "@RefbergSyliusTpayPlugin/config/config.yaml" }
   ```

4. Make sure the Sylius gateway configuration encryption key exists (Sylius generates it during installation):

   ```bash
   bin/console sylius:payment:generate-key
   ```

5. Applications without the Sylius shop only: import the payment notification route, which Sylius otherwise imports
   together with the shop routes, in `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Without this route, capture requests fail and Tpay cannot deliver notifications. In an application with the admin panel,
   the edit page of a Tpay payment method cannot be rendered either, because it shows the notification URL.
   API clients must also send `continue_url` in the capture payload (see [API](#api)).

No database migrations are needed. The plugin keeps its data in `sylius_payment.details`.

## Configuration

In the Tpay merchant panel:

1. **Integration → API**: create an OpenAPI key; you get the client ID and the client secret.
2. **Settings → Notifications**: copy the **security code** and enable **Allow override** of the notification URL.
   The plugin sends its notification URL with every transaction; without this option Tpay sends notifications
   to the address set in the panel instead.

In the Sylius admin panel, go to **Configuration → Payment methods → Create → Tpay** and fill in:

| Field                       | Tpay panel                                  |
|-----------------------------|---------------------------------------------|
| Environment                 | sandbox (`openapi.sandbox.tpay.com`) or production (`api.tpay.com`) |
| Merchant ID                 | merchant ID (digits)                        |
| OpenAPI client ID           | API key – client ID                         |
| OpenAPI client secret       | API key – secret                            |
| Notification security code  | Notifications → Security → security code    |

The client secret and the security code are never shown again after saving.
Leave the fields empty to keep the stored values.

The notification URL is `https://<your-shop>/payment-methods/<PAYMENT_METHOD_CODE>`. The edit page of the
payment method shows it. Tpay does not follow redirects for notifications, so it must be the final public HTTPS URL.

Tpay resends a notification until it gets HTTP 200 with the body `TRUE`: up to 37 times over about two days
(every minute at first, then every 3 and 10 minutes, then hourly). HTTP 404 stops the resending. Tpay publishes no
fixed list of notification IP addresses; notifications are authenticated by their signatures.

If payment requests are processed asynchronously (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` other than `sync://`),
set `framework.router.default_uri` to the public shop URL, so the notification URL is generated correctly.
The notification responses described below also assume `sync://` (see [Notifications](#notifications)).

Requests to Tpay (OAuth, OpenAPI and certificate downloads) use an idle timeout of 15 seconds, set by the
`refberg_tpay.http_timeout` parameter. To change it, set the parameter in the application configuration:

```yaml
parameters:
    refberg_tpay.http_timeout: 30.0
```

### Creating the payment method outside the admin panel

A Tpay payment method created in fixtures, migrations or code must have `usePayum` set to `false`.
Sylius sets it to `true` by default; the admin form turns it off by itself for gateways without a Payum factory.
With `usePayum` set to `true`, the shop hands the payment over to Payum instead of the plugin, so the pay page
fails (Payum has no gateway for it), and Sylius does not encrypt the gateway configuration.

Fixture (`sylius_fixtures`):

```yaml
sylius_fixtures:
    suites:
        default:
            fixtures:
                payment_method:
                    options:
                        custom:
                            tpay:
                                code: TPAY
                                name: Tpay
                                gatewayName: tpay
                                gatewayFactory: !php/const Refberg\SyliusTpayPlugin\TpayGateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_MERCHANT_ID: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_CLIENT_ID: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_CLIENT_SECRET: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_SECURITY_CODE: '...'
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Code, with the `sylius.factory.payment_method` and `sylius.repository.payment_method` services:

```php
use Refberg\SyliusTpayPlugin\TpayGateway;

$paymentMethod = $paymentMethodFactory->createWithGateway(TpayGateway::FACTORY_NAME);
$paymentMethod->setCode('TPAY');
$paymentMethod->setName('Tpay');
$paymentMethod->addChannel($channel);

/** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
$gatewayConfig = $paymentMethod->getGatewayConfig();
$gatewayConfig->setGatewayName('tpay');
$gatewayConfig->setUsePayum(false);
$gatewayConfig->setConfig([
    TpayGateway::CONFIG_ENVIRONMENT => 'sandbox',
    TpayGateway::CONFIG_MERCHANT_ID => '...',
    TpayGateway::CONFIG_CLIENT_ID => '...',
    TpayGateway::CONFIG_CLIENT_SECRET => '...',
    TpayGateway::CONFIG_SECURITY_CODE => '...',
]);

$paymentMethodRepository->add($paymentMethod);
```

## How it works

### Shop

1. The customer places the order and clicks **Pay**. A Tpay transaction is created
   (`hiddenDescription` = `<order number>-<payment ID>-<12 characters of the payment request hash>`)
   and the customer is redirected to the Tpay payment page.
2. After the payment the customer returns to the shop (`/order/after-pay/{hash}?tpay_return=success|error`).
   The plugin asks the Tpay API for the transaction status. While the transaction is still pending
   (for example a bank transfer not booked yet), a customer coming back through the success URL sees the thank-you
   page and the payment stays unpaid; through the error URL, Sylius offers to pay again.
3. Tpay sends a notification to `/payment-methods/{code}`. The payment is completed or failed based on
   the transaction read from the Tpay API.

Opening the pay page again reuses the Tpay transaction that is still in progress, instead of creating a new one.

### API

Create a payment request for a placed order:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "TPAY",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` contains the Tpay payment page, `responseData.transaction_id` and
`responseData.title` identify the transaction. `continue_url` is used for both the success and the error return.
It is optional when the shop is installed; without it, the customer returns to the shop's after-pay page.
Without the shop, a capture request without `continue_url` fails and Tpay is not called.

When the customer is back, ask for the status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "TPAY", "action": "status"}
```

`responseData.payment_state` holds the Sylius payment state and `responseData.tpay_status` the Tpay transaction status.

### Payment state rules

| Tpay transaction status | Sylius payment                                                                  |
|-------------------------|---------------------------------------------------------------------------------|
| `pending`               | unchanged (`new`)                                                               |
| `paid`, `correct`       | `completed`, once                                                               |
| `refund` (full refund)  | `refunded`, when this transaction completed the payment                         |
| `canceled`              | `failed`, only when no other Tpay transaction of this payment is still pending  |

A payment that is completed is never moved back. If a second Tpay transaction of the same payment is paid
(for example after the customer retried in another tab), the Sylius order is not paid twice. The plugin
records a warning in the payment details (`refberg_tpay.warnings`) and logs an error on the `tpay` channel.
Refund that transaction manually in the Tpay panel.

If the customer switches the order to another payment method after being redirected to Tpay, the Tpay transaction
created before the switch stays linked to the payment and can still be paid. The payment is completed by its notification
or by a status check (the customer's return from Tpay or the `status` payment request through the API) once
the Tpay API confirms the transaction is paid; the plugin sets the payment method back to the Tpay method that was paid,
records the `paid_with_previous_payment_method` warning and logs a warning. Refunds of that payment from Sylius
therefore reach Tpay.

A payment cancelled in Sylius before the old Tpay transaction is paid still has the new method, so the cancellation may not
reach that transaction:

- Another Tpay method: the cancellation is sent with the new method's credentials. For a transaction of another
  merchant Tpay is expected to refuse it; the payment request then fails and an error is logged.
- A method outside Tpay: the cancellation is not sent to Tpay. The old transaction stays pending; if the customer pays it,
  the payment stays cancelled and gets the `payment_completed_after_failure` warning.

In both cases refund or cancel the transaction in the Tpay panel.

### Notifications

| Situation                                                                  | Response         | Tpay           |
|----------------------------------------------------------------------------|------------------|----------------|
| Accepted (also a duplicate, a stale status or a transaction that does not match the payment) | 200 `TRUE` | stops |
| Missing or invalid `md5sum` or JWS signature, another merchant ID           | 400, not stored  | resends        |
| Unknown transaction (`tr_crc` / `tr_id` not linked to a payment)            | 404, not stored  | stops          |
| Payment method without credentials, Tpay certificate unavailable            | 503, not stored  | resends        |
| Tpay API unreachable, HTTP 5xx/408/429 or rejecting the plugin credentials (401/403) | 5xx     | resends        |
| Other Tpay API error (e.g. 400, 404) while reading the transaction          | 200 `TRUE`, payment request failed | stops |

The 5xx answers for transient errors rely on the payment request transport being `sync://` (the Sylius default):
the handler's exception reaches the notification endpoint. With an asynchronous transport the endpoint always
answers 200 `TRUE` and errors are left to the Messenger retry strategy.

### Refunds

Click **Refund** on a completed Tpay payment in the admin panel. The plugin first reads the refunds of the
transaction from Tpay, because partial refunds made in the Tpay panel send no notification. Then:

- no earlier refunds: the whole transaction is refunded (empty request body). Tpay moves the transaction to the
  `refund` status and confirms it with a `CHARGEBACK` notification, whose refund statuses are stored;
- earlier refunds that are not canceled (including those still in progress): only the remaining amount is refunded;
- earlier refunds covering the whole payment: Tpay is not called and the refund payment request is completed.

Panel refunds still in progress are subtracted as well. If Tpay cancels such a refund after the refund from Sylius,
the customer gets back only the amount sent to Tpay (nothing when the panel refunds covered the whole payment).
The payment stays `refunded`; when the plugin reads the cancellation from Tpay (at the next `CHARGEBACK` notification),
it records the `refund_canceled` warning and logs an error. Refund the rest manually in the Tpay panel.
The same applies when Tpay cancels a refund requested by Sylius.

Sylius marks the payment as refunded right away, before Tpay processes the refund. If Tpay rejects the refund,
the refund payment request is marked as failed and an error is logged. The refund then has to be handled
in the Tpay panel. Sylius does not retry a failed request automatically, also when Tpay was unavailable
(timeout, HTTP 5xx).

Tpay has no idempotency key for refunds. A refund request is sent only while the refund payment request is `new`,
under the payment lock, and the plugin skips the call when a refund from Sylius is already recorded. A crash between
the Tpay response and the database commit could still leave a refund that Sylius does not know about; the next
refund attempt reads it from Tpay as a refund made outside Sylius.

### Cancellation

When a Tpay payment is cancelled in Sylius (for example when the order is cancelled), the plugin cancels every
pending Tpay transaction of that payment (`POST /transactions/{transactionId}/cancel`). If Tpay refuses because
the transaction was already paid (HTTP 409), the cancel payment request is marked as failed and an error is logged;
the later notification is recorded as a warning and the payment is not completed. Sylius does not retry a failed
cancel request automatically, also when Tpay was unavailable (timeout, HTTP 5xx); check the transaction in the Tpay panel.

### Refunds made in the Tpay panel

A full refund made in the Tpay panel moves the transaction to `refund` and Tpay sends a `CHARGEBACK` notification.
The plugin records the refunds read from the Tpay API as external refunds and moves the payment to `refunded`;
the refund payment request created by that transition sees them and does not refund again.

Partial refunds made in the Tpay panel do not change the transaction status and send no notification, even when
they add up to the whole amount. They show up in the payment details at the next refund from Sylius or `CHARGEBACK`
notification. The payment stays `completed`, because Sylius has no state for a partial refund.

## Security notes

- Secrets are stored in the gateway configuration, which Sylius encrypts.
- Notification signatures are checked with `hash_equals()` (`md5sum`) and `openssl_verify()` (JWS, RS256). The JWS
  certificate must come from `https://secure.tpay.com/x509/` (sandbox: `https://secure.sandbox.tpay.com/x509/`),
  be signed by the Tpay root CA from the same host and be valid at the time of the check. Certificates are cached
  for a day in the `refberg_tpay.cache` pool and downloaded again after a failed check.
- Refunds and cancellations are sent to Tpay only for a payment that is already refunded or cancelled in Sylius,
  so they cannot be triggered through the shop API.
- Secrets and OAuth tokens are never logged or put into payment request data.
  Exception messages contain only HTTP status codes and Tpay error codes.
- The OAuth token is cached in the `refberg_tpay.cache` pool (by default `cache.app`) until shortly before it expires.
- Tpay traffic goes through the `refberg_tpay.http_client` service (an alias of `http_client`).
  You can override it, for example to use a proxy.

## Assumptions checked only against the documentation

These points come from the Tpay documentation and the official PHP SDK, not from a run against the Tpay sandbox.
They are covered by tests against `tests/Support/FakeTpay.php`, which implements them as described; check them with
`tests/Sandbox` before going live.

| Assumption | Where |
|------------|-------|
| `POST /oauth/auth` accepts a JSON body (as the official SDK sends it; the API reference names multipart form data). | `AccessTokenProvider` |
| `GET /transactions/{transactionId}` takes the transaction ID returned on creation; notifications name the transaction by its title (`tr_id`). | `TpayClient`, `NotifyPaymentRequestHandler` |
| A refund status other than `done`, `authorized`, `blik_processed` (finalized) or `cancel`, `declined` (canceled) means the refund is in progress. Refunds listed without a status (no bank account, cards) are in progress. | `TpayRefund`, `TpayClient` |
| `tr_status` is `TRUE` (or `PAID`) for a payment and `CHARGEBACK` for a full refund; partial refunds send no notification. | `NotificationParser` |
| After the customer switches the payment method in Sylius, the old Tpay transaction still accepts a payment. | `ShopPaymentFlowTest` |
| A transaction in another currency than PLN is rejected without a multi-currency agreement. | `FakeTpay` |

## Extending

The services below are registered under their interface. Decorate or replace them by the interface:

| Service ID                                                              | Responsibility                                       |
|-------------------------------------------------------------------------|------------------------------------------------------|
| `Refberg\SyliusTpayPlugin\Api\AccessTokenProviderInterface`             | OAuth token for the Tpay API                         |
| `Refberg\SyliusTpayPlugin\Api\TpayClientInterface`                      | Tpay OpenAPI calls                                   |
| `Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface`          | Tpay credentials from the gateway configuration      |
| `Refberg\SyliusTpayPlugin\Signature\ChecksumVerifierInterface`          | `md5sum` verification                                |
| `Refberg\SyliusTpayPlugin\Signature\JwsVerifierInterface`               | `X-JWS-Signature` verification                       |
| `Refberg\SyliusTpayPlugin\Signature\CertificateProviderInterface`       | Download and cache of the Tpay certificates          |
| `Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface`     | Signed notification parsing                          |
| `Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface`     | Body of the Tpay transaction request                 |
| `Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface`           | `hiddenDescription` values                           |
| `Refberg\SyliusTpayPlugin\Order\TpayTransactionValidatorInterface`      | Check of a Tpay transaction against the Sylius payment |
| `Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface`               | Payment lock                                         |
| `Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface`         | Payment state changes                                |
| `Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface`         | Fetching, checking and applying a Tpay transaction and its refunds |

For example, to change the `hiddenDescription` format:

```yaml
services:
    App\Payment\ExtOrderIdGenerator:
        decorates: Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` must read the payment ID back from every value returned by `generate()`,
because notifications are matched to payments by it.

Classes marked `@internal` (command handlers, `PaymentRequestHandlerTrait`, `Api\Json`) are not covered by the
backward compatibility promise.

## Development

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan reads the compiled test container
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

PHPStan runs at level max on the whole repository with the strict, deprecation, Doctrine, Symfony and PHPUnit rules.
CI also checks `composer normalize`, `composer audit` and runs mutation tests with Infection (`infection.json5`).

Integration tests run the Sylius test application against MySQL:

```bash
docker run -d --name tpay-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

The test application encrypts the gateway configuration with the committed `tests/TestApplication/config/encryption/test-only.key`,
processes payment requests with `sync://` and needs no network (Iconify icons are disabled).
Tests never call Tpay. All requests go to an in-memory fake of the Tpay sandbox (`tests/Support/FakeTpay.php`),
which signs notifications with its own test CA and rejects any other host. The tests include signed, duplicated,
out-of-order and forged notifications. `HeadlessApiPaymentFlowTest` runs without the shop, admin and Twig Hooks bundles.

### Tests against the Tpay sandbox

`tests/Sandbox` runs against the real Tpay sandbox (accounts: [register.sandbox.tpay.com](https://register.sandbox.tpay.com/)).
It is not part of CI and is skipped unless enabled. Credentials are read from environment variables only.

```bash
TPAY_SANDBOX=1 TPAY_SANDBOX_MERCHANT_ID=... TPAY_SANDBOX_CLIENT_ID=... \
TPAY_SANDBOX_CLIENT_SECRET=... TPAY_SANDBOX_SECURITY_CODE=... \
vendor/bin/phpunit --testsuite=sandbox
```

Without more settings it creates, reads and cancels transactions. With `TPAY_SANDBOX_MANUAL=1` it also prints
sandbox payment pages and waits up to five minutes for you to pay or reject them, then checks the payment state and
the refund. Tpay cannot deliver notifications to a local machine, so these tests read the payment state from the Tpay API.
To check notification delivery, run the shop on a public URL (for example through a tunnel).

Manual scenarios before a release: payment, rejected payment, refund from the admin panel, full and partial refund
in the Tpay panel, cancellation, payment method change after the redirect to Tpay.

## License

MIT, see [LICENSE](LICENSE).
