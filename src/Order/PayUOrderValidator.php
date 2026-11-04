<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Order;

use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Checks that an order reported by PayU really belongs to the given Sylius payment.
 */
final readonly class PayUOrderValidator implements PayUOrderValidatorInterface
{
    /**
     * @return list<string> problems found, empty when the order matches
     */
    public function validate(PayUOrder $order, PaymentInterface $payment, PayUCredentials $credentials): array
    {
        $details = PaymentDetails::of($payment);
        $errors = [];

        if (!$details->hasOrder($order->orderId)) {
            $errors[] = 'PayU order is not linked to this payment';
        }

        $expectedExtOrderId = $details->getExtOrderId($order->orderId);
        if (null === $expectedExtOrderId || $order->extOrderId !== $expectedExtOrderId) {
            $errors[] = 'extOrderId does not match';
        }

        if (null !== $order->merchantPosId && $order->merchantPosId !== $credentials->posId) {
            $errors[] = 'merchantPosId does not match';
        }

        if ($order->totalAmount !== $payment->getAmount()) {
            $errors[] = 'amount does not match';
        }

        if ($order->currencyCode !== $payment->getCurrencyCode()) {
            $errors[] = 'currency does not match';
        }

        return $errors;
    }
}
