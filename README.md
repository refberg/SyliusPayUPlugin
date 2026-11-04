# Sylius PayU Plugin

[![Build](https://github.com/refberg/SyliusPayUPlugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusPayUPlugin/actions/workflows/build.yaml)
[![Latest version](https://img.shields.io/packagist/v/refberg/sylius-payu-plugin)](https://packagist.org/packages/refberg/sylius-payu-plugin)
[![Downloads](https://img.shields.io/packagist/dt/refberg/sylius-payu-plugin)](https://packagist.org/packages/refberg/sylius-payu-plugin)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[Polska wersja](README.pl.md)

PayU payment gateway for Sylius 2, built on Sylius Payment Requests and the PayU REST API v2.1.
It does not use Payum.

## Features

- PayU hosted payment page (redirect) in the shop checkout and in the Sylius API.
- Payment state is changed only by a signed PayU notification or by a direct query to the PayU API.
  The customer's return from PayU is never treated as proof of payment.
- Notifications are verified (`OpenPayu-Signature`), then the order is read again from the PayU API
  and checked against the Sylius payment: PayU order ID, `extOrderId`, POS ID, amount and currency.
- Duplicated and out-of-order notifications do not change the result and never pay an order twice.
- Full refunds from the admin panel; refunds made in the PayU panel are synchronized.
- Cancelling a payment in Sylius cancels the open PayU order.
- Credentials are stored encrypted by Sylius and are not written to logs.

Out of scope: saved cards, recurring payments, embedded card form, partial refunds started from Sylius.

## Requirements

| Sylius  | PHP     | Symfony                          |
|---------|---------|----------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                          |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x needs PHP 8.4+) |

The Sylius shop (`SyliusShopBundle`) and admin panel (`SyliusAdminBundle`) are optional, so the plugin also works
in API-only applications.

The `sylius_payment` state machine must use the Symfony Workflow adapter, which is the Sylius default.
The plugin sends refunds and cancellations to PayU from listeners of the `workflow.sylius_payment.completed.refund`
and `workflow.sylius_payment.completed.cancel` events. When `sylius_payment` uses the winzou adapter
(`winzou_state_machine` in `sylius_state_machine_abstraction.graphs_to_adapters_mapping` or `default_adapter`),
refunds and cancellations made in Sylius are not sent to PayU and have to be made in the PayU panel.

## Installation

1. Install the package:

   ```bash
   composer require refberg/sylius-payu-plugin
   ```

2. Register the bundle in `config/bundles.php`, if Symfony Flex did not do it:

   ```php
   Refberg\SyliusPayUPlugin\RefbergSyliusPayUPlugin::class => ['all' => true],
   ```

3. Import the configuration in `config/packages/refberg_sylius_payu.yaml`:

   ```yaml
   imports:
       - { resource: "@RefbergSyliusPayUPlugin/config/config.yaml" }
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

   Without this route, capture requests fail and PayU cannot deliver notifications. In an application with the admin panel,
   the edit page of a PayU payment method cannot be rendered either, because it shows the notification URL.
   API clients must also send `continue_url` in the capture payload (see [API](#api)).

No database migrations are needed. The plugin keeps its data in `sylius_payment.details`.

## Configuration

In the admin panel, go to **Configuration → Payment methods → Create → PayU** and fill in:

| Field                 | PayU panel name                |
|-----------------------|--------------------------------|
| Environment           | sandbox or production          |
| POS ID                | `pos_id`                       |
| Second key (MD5)      | second key (MD5)               |
| OAuth client ID       | OAuth protocol – `client_id`     |
| OAuth client secret   | OAuth protocol – `client_secret` |

The second key and the client secret are never shown again after saving.
Leave the fields empty to keep the stored values.

The notification URL is sent to PayU with every order, so it does not have to be configured in the PayU panel.
It is `https://<your-shop>/payment-methods/<PAYMENT_METHOD_CODE>`.

Keep **automatic collection (autoReceive)** enabled for the POS in the PayU panel.
Orders waiting for manual confirmation (`WAITING_FOR_CONFIRMATION`) stay unpaid in Sylius.

PayU retries a notification until it gets HTTP 200, for up to 72 hours. If your firewall filters incoming traffic,
allow the PayU notification servers:

- production: 185.68.12.10, 185.68.12.11, 185.68.12.12, 185.68.12.26, 185.68.12.27, 185.68.12.28
- sandbox: 185.68.14.10, 185.68.14.11, 185.68.14.12, 185.68.14.26, 185.68.14.27, 185.68.14.28

For manual tests you can use the public PLN sandbox POS published in the
[PayU sandbox documentation](https://developers.payu.com/europe/docs/testing/sandbox/).

If payment requests are processed asynchronously (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` other than `sync://`),
set `framework.router.default_uri` to the public shop URL, so the notification URL is generated correctly.

Requests to PayU (OAuth and REST API) use an idle timeout of 15 seconds, set by the `refberg_payu.http_timeout`
parameter. To change it, set the parameter in the application configuration:

```yaml
parameters:
    refberg_payu.http_timeout: 30.0
```

### Creating the payment method outside the admin panel

A PayU payment method created in fixtures, migrations or code must have `usePayum` set to `false`.
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
                            payu:
                                code: PAYU
                                name: PayU
                                gatewayName: payu
                                gatewayFactory: !php/const Refberg\SyliusPayUPlugin\PayUGateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusPayUPlugin\PayUGateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusPayUPlugin\PayUGateway::CONFIG_POS_ID: '...'
                                    !php/const Refberg\SyliusPayUPlugin\PayUGateway::CONFIG_SECOND_KEY: '...'
                                    !php/const Refberg\SyliusPayUPlugin\PayUGateway::CONFIG_OAUTH_CLIENT_ID: '...'
                                    !php/const Refberg\SyliusPayUPlugin\PayUGateway::CONFIG_OAUTH_CLIENT_SECRET: '...'
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Code, with the `sylius.factory.payment_method` and `sylius.repository.payment_method` services:

```php
use Refberg\SyliusPayUPlugin\PayUGateway;

$paymentMethod = $paymentMethodFactory->createWithGateway(PayUGateway::FACTORY_NAME);
$paymentMethod->setCode('PAYU');
$paymentMethod->setName('PayU');
$paymentMethod->addChannel($channel);

/** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
$gatewayConfig = $paymentMethod->getGatewayConfig();
$gatewayConfig->setGatewayName('payu');
$gatewayConfig->setUsePayum(false);
$gatewayConfig->setConfig([
    PayUGateway::CONFIG_ENVIRONMENT => 'sandbox',
    PayUGateway::CONFIG_POS_ID => '...',
    PayUGateway::CONFIG_SECOND_KEY => '...',
    PayUGateway::CONFIG_OAUTH_CLIENT_ID => '...',
    PayUGateway::CONFIG_OAUTH_CLIENT_SECRET => '...',
]);

$paymentMethodRepository->add($paymentMethod);
```

## How it works

### Shop

1. The customer places the order and clicks **Pay**. A PayU order is created and the customer is redirected to PayU.
2. After the payment the customer returns to the shop (`/order/after-pay/{hash}`). The plugin asks the PayU API
   for the order status. Query parameters added by PayU (e.g. `error=501`) are ignored.
   While PayU is still processing the payment, the customer sees the thank-you page and the payment stays unpaid.
3. PayU sends a notification to `/payment-methods/{code}`. The payment is completed or failed based on
   the order state read from the PayU API.

Opening the pay page again reuses the PayU order that is still in progress, instead of creating a new one.

### API

Create a payment request for a placed order:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "PAYU",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` contains the PayU payment page. `continue_url` is optional when the shop is installed.
Without it, the customer returns to the shop's after-pay page. Without the shop, a capture request without
`continue_url` fails and PayU is not called.

When the customer is back, ask for the status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "PAYU", "action": "status"}
```

`responseData.payment_state` holds the Sylius payment state and `responseData.payu_status` the PayU order status.

### Payment state rules

| PayU order status                      | Sylius payment                                                           |
|----------------------------------------|--------------------------------------------------------------------------|
| `NEW`, `PENDING`, `WAITING_FOR_CONFIRMATION` | unchanged (`new`)                                                  |
| `COMPLETED`                            | `completed`, once                                                        |
| `CANCELED`                             | `failed`, only when no other PayU order of this payment is still open    |

A payment that is completed is never moved back. If a second PayU order of the same payment is paid
(for example after the customer retried in another tab), the Sylius order is not paid twice. The plugin
records a warning in the payment details (`refberg_payu.warnings`) and logs an error on the `payu` channel.
Refund that payment manually in the PayU panel.

If the customer switches the order to another payment method after being redirected to PayU, the PayU order
created before the switch stays linked to the payment and can still be paid. Its notification completes the payment;
the plugin sets the payment method back to the PayU method that was paid, records the `paid_with_previous_payment_method`
warning and logs a warning. Refunds of that payment from Sylius therefore reach PayU.

A payment cancelled in Sylius before the old PayU order is paid still has the new method, so the cancellation may not
reach that order:

- Another PayU method: the cancellation is sent with the new method's credentials. For an order of another POS PayU
  is expected to refuse it; the payment request then fails and an error is logged.
- A method outside PayU: the cancellation is not sent to PayU. The old PayU order stays open; if the customer pays it,
  the payment stays cancelled and gets the `payment_completed_after_failure` warning.

In both cases refund or cancel the order in the PayU panel.

Notifications with a missing or invalid signature get HTTP 400 and are not stored. Notifications for unknown
orders get HTTP 404. When the PayU API cannot be reached or rejects the plugin credentials (HTTP 401/403),
the endpoint answers with HTTP 5xx, so PayU retries later.

### Refunds

Click **Refund** on a completed PayU payment in the admin panel. The plugin requests a full refund in PayU
(`extRefundId` is the payment request ID, so a redelivered request does not create a second refund). The final
refund status from the PayU notification is stored in the payment details.

Refunds already made in the PayU panel (except canceled ones) are subtracted from the requested amount. When they
cover the whole payment, the plugin does not call PayU and completes the refund payment request.

Sylius marks the payment as refunded right away, before PayU confirms the refund. If PayU rejects the refund,
the refund payment request is marked as failed and an error is logged. The refund then has to be handled
in the PayU panel. Sylius does not retry a failed request automatically, also when PayU was unavailable
(timeout, HTTP 5xx).

### Cancellation

When a PayU payment is cancelled in Sylius (for example when the order is cancelled), the plugin cancels every
PayU order of that payment that may still be paid (`DELETE /api/v2_1/orders/{orderId}`). If PayU refuses because
the order was already paid, the cancel payment request is marked as failed and an error is logged; the later
COMPLETED notification is recorded as a warning and the payment is not completed. Sylius does not retry a failed
cancel request automatically, also when PayU was unavailable (timeout, HTTP 5xx); check the order in the PayU panel.

### Refunds made in the PayU panel

A signed refund notification for a refund that Sylius did not request is recorded in the payment details.
When finalized refunds cover the whole payment amount, the payment is moved to `refunded` without sending another
refund to PayU. Partial refunds only show up in the payment details, because Sylius has no state for them.

## Security notes

- Secrets are stored in the gateway configuration, which Sylius encrypts.
- Refunds and cancellations are sent to PayU only for a payment that is already refunded or cancelled in Sylius,
  so they cannot be triggered through the shop API.
- Secrets and OAuth tokens are never logged or put into payment request data.
  Exception messages contain only HTTP and PayU status codes.
- The OAuth token is cached in the `refberg_payu.cache` pool (by default `cache.app`) until shortly before it expires.
- PayU traffic goes through the `refberg_payu.http_client` service (an alias of `http_client`).
  You can override it, for example to use a proxy.

## Extending

The services below are registered under their interface. Decorate or replace them by the interface:

| Service ID                                                          | Responsibility                                   |
|---------------------------------------------------------------------|--------------------------------------------------|
| `Refberg\SyliusPayUPlugin\Api\AccessTokenProviderInterface`         | OAuth token for the PayU API                     |
| `Refberg\SyliusPayUPlugin\Api\PayUClientInterface`                  | PayU REST API calls                              |
| `Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface`      | PayU credentials from the gateway configuration  |
| `Refberg\SyliusPayUPlugin\Signature\SignatureVerifierInterface`     | `OpenPayu-Signature` header verification         |
| `Refberg\SyliusPayUPlugin\Notification\NotificationParserInterface` | Signed notification parsing                      |
| `Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface`       | Body of the PayU order request                   |
| `Refberg\SyliusPayUPlugin\Order\ExtOrderIdGeneratorInterface`       | `extOrderId` values                              |
| `Refberg\SyliusPayUPlugin\Order\PayUOrderValidatorInterface`        | Check of a PayU order against the Sylius payment |
| `Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface`           | Payment lock                                     |
| `Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface`     | Payment state changes                            |
| `Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface`     | Fetching, checking and applying a PayU order     |

For example, to change the `extOrderId` format:

```yaml
services:
    App\Payment\ExtOrderIdGenerator:
        decorates: Refberg\SyliusPayUPlugin\Order\ExtOrderIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` must read the payment ID back from every value returned by `generate()`,
because notifications are matched to payments by it.

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

PHPStan runs at level max with the strict, deprecation, Doctrine, Symfony and PHPUnit rules. CI also checks
`composer normalize`, `composer audit` and runs mutation tests with Infection (PHP 8.3+, `infection.json5`).

Integration tests run the Sylius test application against MySQL:

```bash
docker run -d --name payu-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Tests never call PayU. All requests go to an in-memory fake of the PayU sandbox (`tests/Support/FakePayU.php`),
which rejects any other host. The tests include signed, duplicated, out-of-order and forged notifications.

### Tests against the PayU sandbox

`tests/Sandbox` runs the whole flow against the real PayU sandbox: payment in the shop, payment on the PayU page
(accepted and rejected, using the provider simulator), status check, refund and cancellation. It is not part of CI
and is skipped unless enabled. Credentials are read from environment variables only; you can use the public
sandbox POS from the [PayU sandbox documentation](https://developers.payu.com/europe/docs/testing/sandbox/).

```bash
npm install playwright && npx playwright install chromium
PAYU_SANDBOX=1 PAYU_SANDBOX_POS_ID=... PAYU_SANDBOX_SECOND_KEY=... \
PAYU_SANDBOX_CLIENT_ID=... PAYU_SANDBOX_CLIENT_SECRET=... \
vendor/bin/phpunit --testsuite=sandbox
```

PayU cannot deliver notifications to a local machine, so these tests read the payment state from the PayU API.
To check notification delivery, run the shop on a public URL (for example through a tunnel).

## License

MIT, see [LICENSE](LICENSE).
