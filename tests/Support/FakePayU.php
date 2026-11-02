<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * In-memory imitation of the PayU sandbox REST API, used as MockHttpClient callback.
 *
 * Any request to a host other than the PayU sandbox throws, so tests can never reach production.
 */
final class FakePayU
{
    public const HOST = 'secure.snd.payu.com';

    public const CLIENT_ID = '300046';

    public const CLIENT_SECRET = 'test-client-secret';

    public const POS_ID = '300046';

    public const SECOND_KEY = 'test-second-key';

    /** @var array<string, array<string, string>> */
    private array $orders = [];

    /** @var array<array-key, array<string, mixed>> */
    private array $refunds = [];

    /** @var list<array{method: string, path: string, body: string, headers: array<string, string>}> */
    private array $requests = [];

    /** @var list<array{int, array<string, mixed>}> */
    private array $queuedFailures = [];

    private int $tokenCounter = 0;

    /** @var array<string, true> */
    private array $validTokens = [];

    private int $counter = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (self::HOST !== $host) {
            throw new \LogicException(sprintf('Tests must not call "%s". Only the PayU sandbox fake is available.', $host));
        }

        $path = $parts['path'] ?? '/';
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (is_string($header) && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];

        if ('POST' === $method && '/pl/standard/user/oauth/authorize' === $path) {
            return $this->authorize($body);
        }

        $authorization = $headers['authorization'] ?? '';
        if (!isset($this->validTokens[substr($authorization, 7)]) || !str_starts_with($authorization, 'Bearer ')) {
            return self::json(401, ['error' => 'invalid_token']);
        }

        if ([] !== $this->queuedFailures) {
            [$status, $data] = array_shift($this->queuedFailures);

            return self::json($status, $data);
        }

        if ('POST' === $method && '/api/v2_1/orders' === $path) {
            return $this->createOrder($body);
        }

        if (1 === preg_match('#^/api/v2_1/orders/([^/]+)/refunds$#', $path, $matches) && 'POST' === $method) {
            return $this->createRefund(rawurldecode($matches[1]), $body);
        }

        if (1 === preg_match('#^/api/v2_1/orders/([^/]+)$#', $path, $matches) && 'GET' === $method) {
            return $this->retrieveOrder(rawurldecode($matches[1]));
        }

        if (1 === preg_match('#^/api/v2_1/orders/([^/]+)$#', $path, $matches) && 'DELETE' === $method) {
            return $this->cancelOrder(rawurldecode($matches[1]));
        }

        return self::json(404, ['status' => ['statusCode' => 'DATA_NOT_FOUND']]);
    }

    /**
     * Simulates a refund created by the merchant in the PayU panel, outside Sylius.
     */
    public function createPanelRefund(string $orderId, int $amount): string
    {
        $order = $this->order($orderId);
        $refundId = sprintf('%d', 7000000 + count($this->refunds) + 1);
        $this->refunds[$refundId] = [
            'refundId' => $refundId,
            'amount' => (string) $amount,
            'currencyCode' => $order['currencyCode'],
            'description' => 'Panel refund',
            'status' => 'PENDING',
        ];

        return $refundId;
    }

    public function setOrderStatus(string $orderId, string $status): void
    {
        $this->order($orderId);
        $this->orders[$orderId]['status'] = $status;
    }

    /**
     * Changes what PayU reports for the order, to simulate tampering or a mismatch.
     */
    public function overrideOrder(string $orderId, string $field, string $value): void
    {
        $this->order($orderId);
        $this->orders[$orderId][$field] = $value;
    }

    public function failNextRequest(int $httpStatus, string $payuStatusCode = 'ERROR_INTERNAL', ?string $codeLiteral = null): void
    {
        $status = ['statusCode' => $payuStatusCode];
        if (null !== $codeLiteral) {
            $status['codeLiteral'] = $codeLiteral;
        }

        $this->queuedFailures[] = [$httpStatus, ['status' => $status]];
    }

    /**
     * @return array<string, string>
     */
    public function order(string $orderId): array
    {
        return $this->orders[$orderId] ?? throw new \OutOfBoundsException(sprintf('Unknown fake PayU order "%s".', $orderId));
    }

    public function lastOrderId(): string
    {
        $last = array_key_last($this->orders);

        return null !== $last ? (string) $last : throw new \LogicException('No PayU order has been created.');
    }

    /**
     * @return list<string>
     */
    public function orderIds(): array
    {
        return array_map('strval', array_keys($this->orders));
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    /**
     * @return list<array{method: string, path: string, body: string, headers: array<string, string>}>
     */
    public function requests(?string $method = null, ?string $pathPattern = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => (null === $method || $request['method'] === $method) &&
                (null === $pathPattern || 1 === preg_match($pathPattern, $request['path'])),
        ));
    }

    /**
     * Builds an order notification body as PayU sends it, with the current order data.
     */
    public function orderNotificationBody(string $orderId, ?string $status = null): string
    {
        $order = $this->order($orderId);
        if (null !== $status) {
            $order['status'] = $status;
        }

        return json_encode([
            'order' => $order,
            'localReceiptDateTime' => '2026-01-01T12:00:00.000+01:00',
            'properties' => [['name' => 'PAYMENT_ID', 'value' => '5000000001']],
        ], \JSON_THROW_ON_ERROR);
    }

    public function refundNotificationBody(string $orderId, string $refundId, string $status): string
    {
        $order = $this->order($orderId);
        $refund = $this->refunds[$refundId] ?? throw new \OutOfBoundsException('Unknown refund.');

        return json_encode([
            'orderId' => $orderId,
            'extOrderId' => $order['extOrderId'],
            'refund' => array_merge($refund, ['status' => $status]),
        ], \JSON_THROW_ON_ERROR);
    }

    public static function signatureHeader(string $body, string $secondKey = self::SECOND_KEY, string $algorithm = 'SHA-256'): string
    {
        $phpAlgorithm = 'MD5' === $algorithm ? 'md5' : 'sha256';

        return sprintf('sender=checkout;signature=%s;algorithm=%s;content=DOCUMENT', hash($phpAlgorithm, $body . $secondKey), $algorithm);
    }

    private function authorize(string $body): ResponseInterface
    {
        parse_str($body, $form);
        if (
            ($form['grant_type'] ?? null) !== 'client_credentials' ||
            ($form['client_id'] ?? null) !== self::CLIENT_ID ||
            ($form['client_secret'] ?? null) !== self::CLIENT_SECRET
        ) {
            return self::json(401, ['error' => 'invalid_client']);
        }

        $token = sprintf('fake-token-%d', ++$this->tokenCounter);
        $this->validTokens[$token] = true;

        return self::json(200, ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => 43199, 'grant_type' => 'client_credentials']);
    }

    private function createOrder(string $body): ResponseInterface
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);

        foreach (['notifyUrl', 'customerIp', 'merchantPosId', 'description', 'currencyCode', 'totalAmount', 'extOrderId', 'products'] as $field) {
            if (!isset($data[$field])) {
                return self::json(400, ['status' => ['statusCode' => 'ERROR_VALUE_MISSING', 'statusDesc' => 'Missing ' . $field]]);
            }
        }

        foreach ($this->orders as $order) {
            if ($order['extOrderId'] === $data['extOrderId']) {
                return self::json(400, ['status' => ['statusCode' => 'ERROR_ORDER_NOT_UNIQUE']]);
            }
        }

        $orderId = sprintf('FAKE%08dPAYU', ++$this->counter);
        $this->orders[$orderId] = [
            'orderId' => $orderId,
            'extOrderId' => self::str($data['extOrderId']),
            'status' => 'NEW',
            'totalAmount' => self::str($data['totalAmount']),
            'currencyCode' => self::str($data['currencyCode']),
            'merchantPosId' => self::str($data['merchantPosId']),
            'notifyUrl' => self::str($data['notifyUrl']),
            'continueUrl' => self::str($data['continueUrl'] ?? ''),
        ];

        return self::json(302, [
            'status' => ['statusCode' => 'SUCCESS'],
            'redirectUri' => 'https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId,
            'orderId' => $orderId,
            'extOrderId' => $data['extOrderId'],
        ], ['Location' => 'https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId]);
    }

    private function retrieveOrder(string $orderId): ResponseInterface
    {
        if (!isset($this->orders[$orderId])) {
            return self::json(404, ['status' => ['statusCode' => 'DATA_NOT_FOUND']]);
        }

        return self::json(200, ['orders' => [$this->orders[$orderId]], 'status' => ['statusCode' => 'SUCCESS']]);
    }

    private function cancelOrder(string $orderId): ResponseInterface
    {
        if (!isset($this->orders[$orderId])) {
            return self::json(404, ['status' => ['statusCode' => 'DATA_NOT_FOUND']]);
        }

        if ('COMPLETED' === $this->orders[$orderId]['status']) {
            return self::json(400, ['status' => ['statusCode' => 'ERROR_VALUE_INVALID', 'statusDesc' => 'Only orders which are not COMPLETED can be cancelled']]);
        }

        $this->orders[$orderId]['status'] = 'CANCELED';

        return self::json(200, ['orderId' => $orderId, 'extOrderId' => $this->orders[$orderId]['extOrderId'], 'status' => ['statusCode' => 'SUCCESS']]);
    }

    private function createRefund(string $orderId, string $body): ResponseInterface
    {
        if (!isset($this->orders[$orderId]) || 'COMPLETED' !== $this->orders[$orderId]['status']) {
            return self::json(400, ['status' => ['statusCode' => 'ERROR_VALUE_INVALID']]);
        }

        /** @var array{refund: array<string, mixed>} $data */
        $data = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        $refund = $data['refund'];

        foreach ($this->refunds as $existing) {
            if (isset($existing['extRefundId']) && $existing['extRefundId'] === ($refund['extRefundId'] ?? null)) {
                return self::json(200, ['orderId' => $orderId, 'refund' => $existing, 'status' => ['statusCode' => 'SUCCESS']]);
            }
        }

        $refundId = sprintf('%d', 5000000 + count($this->refunds) + 1);
        $this->refunds[$refundId] = [
            'refundId' => $refundId,
            'extRefundId' => $refund['extRefundId'] ?? null,
            'amount' => self::str($refund['amount'] ?? $this->orders[$orderId]['totalAmount']),
            'currencyCode' => $this->orders[$orderId]['currencyCode'],
            'description' => $refund['description'] ?? '',
            'status' => 'PENDING',
        ];

        return self::json(200, ['orderId' => $orderId, 'refund' => $this->refunds[$refundId], 'status' => ['statusCode' => 'SUCCESS', 'statusDesc' => 'Refund queued for processing']]);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $data, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => array_merge(['Content-Type' => 'application/json'], $headers),
        ]);
    }
}
