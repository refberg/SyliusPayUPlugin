<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Notification;

use JsonException;
use Refberg\SyliusPayUPlugin\Api\Json;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifierInterface;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Verifies the signature of a PayU notification and extracts its identifiers.
 * Returns null for anything that is not a correctly signed, well-formed notification.
 */
final readonly class NotificationParser implements NotificationParserInterface
{
    public function __construct(private SignatureVerifierInterface $signatureVerifier)
    {
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    public function parse(string $rawBody, array $headers, PayUCredentials $credentials): ?Notification
    {
        $signature = $this->signatureVerifier->extractHeader($headers);
        if (!$this->signatureVerifier->verify($rawBody, $signature, $credentials->secondKey)) {
            return null;
        }

        try {
            $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $this->fromArray($data) : null;
    }

    /**
     * Parses a notification stored in PaymentRequest::$payload by Sylius' NotifyPayloadProcessor.
     */
    public function parseStoredPayload(mixed $payload, PayUCredentials $credentials): ?Notification
    {
        $httpRequest = is_array($payload) ? ($payload['http_request'] ?? null) : null;
        if (!is_array($httpRequest)) {
            return null;
        }

        $content = $httpRequest['content'] ?? null;
        $headers = $httpRequest['headers'] ?? [];

        if (!is_string($content) || !is_array($headers)) {
            return null;
        }

        return $this->parse($content, $headers, $credentials);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function fromArray(array $data): ?Notification
    {
        $refund = $data['refund'] ?? null;
        if (is_array($refund)) {
            $orderId = Json::string($data, 'orderId');
            if (null === $orderId) {
                return null;
            }

            return new Notification(
                type: Notification::TYPE_REFUND,
                orderId: $orderId,
                extOrderId: Json::string($data, 'extOrderId'),
                refundId: Json::string($refund, 'refundId'),
                extRefundId: Json::string($refund, 'extRefundId'),
                refundStatus: Json::string($refund, 'status'),
                refundAmount: is_numeric($refund['amount'] ?? null) ? (int) $refund['amount'] : null,
                refundCurrency: Json::string($refund, 'currencyCode'),
            );
        }

        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $orderId = Json::string($order, 'orderId');
        if (null === $orderId) {
            return null;
        }

        return new Notification(
            type: Notification::TYPE_ORDER,
            orderId: $orderId,
            extOrderId: Json::string($order, 'extOrderId'),
        );
    }
}
