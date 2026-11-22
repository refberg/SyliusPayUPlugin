# Changelog

## Unreleased

### Added

- Support for applications without the Sylius shop (`SyliusShopBundle`) or the admin panel (`SyliusAdminBundle`, `SyliusTwigHooksBundle`). Without the shop, the application must import the payment notification route `@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml`.
- Service interfaces, which are also the service IDs, for decoration: `Notification\NotificationParserInterface`, `Order\ExtOrderIdGeneratorInterface`, `Order\PayUOrderValidatorInterface`, `Payment\PaymentStateApplierInterface` and `Payment\PaymentSynchronizerInterface` (namespace `Refberg\SyliusPayUPlugin`). The `RESULT_*` constants are defined in `PaymentStateApplierInterface` and `PaymentSynchronizerInterface`.
- When Sylius cancels the capture payment request of a PayU order that is still `NEW` (e.g. the customer switched the payment method), the plugin asks PayU to cancel that order. The result is only logged.
- Constants for the gateway configuration keys (`PayUGateway::CONFIG_*`) and the final refund statuses (`RefundCreated::STATUS_FINALIZED`, `RefundCreated::STATUS_CANCELED`, `RefundCreated::isFinalStatus()`).

### Changed

- PayU orders `PENDING` and `WAITING_FOR_CONFIRMATION` move a `new` payment that still uses a PayU method to `processing` (1.0.0 left it `new`), so while PayU processes the payment Sylius blocks changing the payment method in the shop and through the API (`PATCH` of the payment), and the shop **Pay** leads to the thank-you page. The applier returns the new result `PaymentStateApplierInterface::RESULT_PROCESSING`.
- A PayU order paid after the Sylius payment was already completed by another order, failed or cancelled is logged as `critical` instead of `error`. The warning names in the payment details are unchanged.
- New optional last constructor arguments: `PaymentStateApplier` (`CredentialsResolverInterface`), `NotifyPaymentRequestHandler` and `StatusPaymentRequestHandler` (`PaymentRequestRepositoryInterface`).
- Requires `sylius/sylius` 2.2.12+ or 2.3.2+: 2.2.9 limits the payment request actions available to shop API clients, and the security fixes of 2.2.12 reached the 2.3 line in 2.3.2.

### Deprecated

- Service IDs `Refberg\SyliusPayUPlugin\Notification\NotificationParser`, `Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator`, `Refberg\SyliusPayUPlugin\Order\PayUOrderValidator`, `Refberg\SyliusPayUPlugin\Payment\PaymentStateApplier` and `Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer`; use the corresponding interfaces. The old IDs are aliases and will be removed in 2.0.
- `Api\Model\PayUOrder::isFinal()` and `Payment\PaymentDetails::findOrderIdByExtOrderId()`, unused by the plugin; they will be removed in 2.0. Use `PayUOrder::isFinalStatus()` instead of `isFinal()`.

### Fixed

- Refund and cancel payment requests reach PayU only for a payment that is already refunded or cancelled in Sylius.
- A refund from the admin panel after a partial refund in the PayU panel requests the remaining amount; when panel refunds already cover the payment, PayU is not called.
- A payment whose method was changed after the redirect to PayU is completed by the PayU notification or a status check (the customer's return from PayU or the `status` payment request through the API) with the PayU method restored and the `paid_with_previous_payment_method` warning, so refunds from Sylius reach PayU.
- The capture payment request is completed or failed when the notification or the status check finds its PayU order completed or canceled (the status check also closes capture requests left processing for PayU orders that were already finished), so paying again after a canceled PayU order creates a new PayU order instead of reusing the dead redirect URL.
- A canceled PayU order no longer fails a payment that was switched to a payment method outside PayU.
- Capture checks the payment state after acquiring the payment lock, so a payment completed in the meantime gets no new PayU order.
- A notification is answered with HTTP 5xx, so PayU retries it, when the PayU API rejects the plugin credentials (HTTP 401/403).
- A refund made in the PayU panel that is finalized after a PENDING notification marks the payment as refunded once finalized refunds cover it.
- The cancel payment request completes only when PayU reports the order as `CANCELED` after the cancel call; PayU answers the cancel of a paid order with success, which the plugin used to record as canceled.

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
