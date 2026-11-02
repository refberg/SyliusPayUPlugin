<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Typed access to the PayU data stored in Payment::$details[PayUGateway::DETAILS_KEY].
 *
 * Shape:
 *   orders:             map orderId => {ext_order_id, status, amount, currency}
 *   current_order_id:   PayU order created most recently for this payment
 *   completed_order_id: PayU order that completed this payment
 *   refunds:            map extRefundId => {refund_id, status, amount, currency}
 *   warnings:           list of {type, order_id}
 *
 * Only scalars and arrays are stored, so the data stays JSON-serializable.
 */
final class PaymentDetails
{
    public const WARNING_DUPLICATE_PAYMENT = 'duplicate_payment';

    public const WARNING_LATE_PAYMENT = 'payment_completed_after_failure';

    /** @var array<string, mixed> */
    private array $data;

    private function __construct(private readonly PaymentInterface $payment)
    {
        $data = $payment->getDetails()[PayUGateway::DETAILS_KEY] ?? [];
        $this->data = is_array($data) ? self::stringKeys($data) : [];
    }

    public static function of(PaymentInterface $payment): self
    {
        return new self($payment);
    }

    public function save(): void
    {
        $details = $this->payment->getDetails();
        $details[PayUGateway::DETAILS_KEY] = $this->data;
        $this->payment->setDetails($details);
    }

    public function addOrder(string $orderId, string $extOrderId, int $amount, string $currency): void
    {
        $orders = $this->orders();
        $orders[$orderId] = [
            'ext_order_id' => $extOrderId,
            'status' => PayUOrder::STATUS_NEW,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['orders'] = $orders;
        $this->data['current_order_id'] = $orderId;
    }

    public function hasOrder(string $orderId): bool
    {
        return isset($this->orders()[$orderId]);
    }

    public function findOrderIdByExtOrderId(string $extOrderId): ?string
    {
        foreach ($this->orders() as $orderId => $order) {
            if (($order['ext_order_id'] ?? null) === $extOrderId) {
                return (string) $orderId;
            }
        }

        return null;
    }

    public function getExtOrderId(string $orderId): ?string
    {
        $value = $this->orders()[$orderId]['ext_order_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getOrderStatus(string $orderId): ?string
    {
        $value = $this->orders()[$orderId]['status'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Records the latest known status. A final status (COMPLETED, CANCELED) is never overwritten.
     */
    public function recordOrderStatus(string $orderId, string $status): void
    {
        $orders = $this->orders();
        if (!isset($orders[$orderId])) {
            return;
        }

        $current = $orders[$orderId]['status'] ?? null;
        if (PayUOrder::isFinalStatus(is_string($current) ? $current : null)) {
            return;
        }

        $orders[$orderId]['status'] = $status;
        $this->data['orders'] = $orders;
    }

    public function getCurrentOrderId(): ?string
    {
        $value = $this->data['current_order_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getCompletedOrderId(): ?string
    {
        $value = $this->data['completed_order_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function setCompletedOrderId(string $orderId): void
    {
        $this->data['completed_order_id'] = $orderId;
    }

    /**
     * @return list<string> order IDs whose last known status is not final, newest first
     */
    public function getOpenOrderIds(): array
    {
        $open = [];
        foreach ($this->orders() as $orderId => $order) {
            $status = $order['status'] ?? null;
            if (!PayUOrder::isFinalStatus(is_string($status) ? $status : null)) {
                $open[] = (string) $orderId;
            }
        }

        return array_reverse($open);
    }

    public function hasOpenOrders(): bool
    {
        return [] !== $this->getOpenOrderIds();
    }

    public function addWarning(string $type, string $orderId): void
    {
        $warnings = $this->warnings();
        foreach ($warnings as $warning) {
            if (($warning['type'] ?? null) === $type && ($warning['order_id'] ?? null) === $orderId) {
                return;
            }
        }

        $warnings[] = ['type' => $type, 'order_id' => $orderId];
        $this->data['warnings'] = $warnings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function warnings(): array
    {
        $warnings = $this->data['warnings'] ?? [];
        if (!is_array($warnings)) {
            return [];
        }

        $result = [];
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $result[] = self::stringKeys($warning);
            }
        }

        return $result;
    }

    public function recordRefund(string $extRefundId, string $refundId, string $status, int $amount, string $currency): void
    {
        $refunds = $this->refunds();
        $refunds[$extRefundId] = [
            'refund_id' => $refundId,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['refunds'] = $refunds;
    }

    /**
     * @return bool false when the refund is unknown
     */
    public function updateRefundStatus(string $refundId, ?string $extRefundId, string $status): bool
    {
        $refunds = $this->refunds();
        foreach ($refunds as $key => $refund) {
            if (($refund['refund_id'] ?? null) !== $refundId && (string) $key !== $extRefundId) {
                continue;
            }

            $current = $refund['status'] ?? null;
            if (in_array($current, ['FINALIZED', 'CANCELED'], true)) {
                return true;
            }

            $refunds[$key]['status'] = $status;
            $this->data['refunds'] = $refunds;

            return true;
        }

        return false;
    }

    /**
     * Records a refund made outside Sylius (e.g. in the PayU panel), keyed by the PayU refund ID.
     *
     * @return bool false when the refund was already known
     */
    public function recordExternalRefund(string $refundId, string $status, int $amount, string $currency): bool
    {
        foreach ($this->refunds() as $refund) {
            if (($refund['refund_id'] ?? null) === $refundId) {
                return false;
            }
        }

        $refunds = $this->refunds();
        $refunds['payu-' . $refundId] = [
            'refund_id' => $refundId,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'external' => true,
        ];
        $this->data['refunds'] = $refunds;

        return true;
    }

    public function finalizedRefundAmount(): int
    {
        $total = 0;
        foreach ($this->refunds() as $refund) {
            $amount = $refund['amount'] ?? 0;
            if ('FINALIZED' === ($refund['status'] ?? null) && is_int($amount)) {
                $total += $amount;
            }
        }

        return $total;
    }

    public function hasActiveRefund(): bool
    {
        foreach ($this->refunds() as $refund) {
            if (($refund['status'] ?? null) !== 'CANCELED') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function refunds(): array
    {
        $refunds = $this->data['refunds'] ?? [];

        return is_array($refunds) ? self::mapOfArrays($refunds) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orders(): array
    {
        $orders = $this->data['orders'] ?? [];

        return is_array($orders) ? self::mapOfArrays($orders) : [];
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private static function mapOfArrays(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $result[(string) $key] = self::stringKeys($item);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
