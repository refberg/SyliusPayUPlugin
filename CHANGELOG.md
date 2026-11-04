# Changelog

## Unreleased

### Added

- Support for applications without the Sylius shop (`SyliusShopBundle`) or the admin panel (`SyliusAdminBundle`, `SyliusTwigHooksBundle`). Without the shop, the application must import the payment notification route `@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml`.

### Changed

- Requires `sylius/sylius` 2.2.9 or newer, which limits the payment request actions available to shop API clients.

### Fixed

- Refund and cancel payment requests reach PayU only for a payment that is already refunded or cancelled in Sylius.
- A refund from the admin panel after a partial refund in the PayU panel requests the remaining amount; when panel refunds already cover the payment, PayU is not called.
- A PayU notification for a payment whose method was changed after the redirect to PayU is accepted; the payment is completed with the `paid_with_previous_payment_method` warning.
- Capture checks the payment state after acquiring the payment lock, so a payment completed in the meantime gets no new PayU order.
- A notification is answered with HTTP 5xx, so PayU retries it, when the PayU API rejects the plugin credentials (HTTP 401/403).
- A refund made in the PayU panel that is finalized after a PENDING notification marks the payment as refunded once finalized refunds cover it.

### Removed

- `Refberg\SyliusPayUPlugin\DependencyInjection\Compiler\RemoveShopServicesPass`; shop services are registered only when `SyliusShopBundle` is enabled.
- `config/twig_hooks/admin.yaml`; the admin Twig hooks are registered by the plugin extension when `SyliusTwigHooksBundle` is enabled.

## 1.0.0 (2026-11-02)

First release.

- PayU REST API v2.1 gateway (`refberg_payu`) built on Sylius Payment Requests, without Payum.
- Shop and API checkout with redirect to the PayU payment page.
- Status check after the customer returns, based on the PayU API.
- Signed notification endpoint with order, amount, currency and POS checks; idempotent and order-independent.
- Full refunds from the admin panel.
- Cancelling a payment in Sylius cancels open PayU orders.
- Refunds made in the PayU panel are recorded; a full refund marks the payment as refunded.
- Support for Sylius 2.2 and 2.3, PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.x (tested on 8.1).
