# Changelog

## Unreleased

- PayU REST API v2.1 gateway (`refberg_payu`) built on Sylius Payment Requests, without Payum.
- Shop and API checkout with redirect to the PayU payment page.
- Status check after the customer returns, based on the PayU API.
- Signed notification endpoint with order, amount, currency and POS checks; idempotent and order-independent.
- Full refunds from the admin panel.
- Cancelling a payment in Sylius cancels open PayU orders.
- Refunds made in the PayU panel are recorded; a full refund marks the payment as refunded.
- Support for Sylius 2.2 and 2.3.
