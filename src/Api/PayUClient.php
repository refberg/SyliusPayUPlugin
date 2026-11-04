<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUTransportException;
use Refberg\SyliusPayUPlugin\Api\Model\OrderCreated;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function in_array;
use function is_array;
use function is_numeric;
use function mb_substr;
use function rawurlencode;
use function sprintf;
use function str_starts_with;

/**
 * Minimal PayU REST API v2.1 client.
 *
 * Logs only HTTP status codes, PayU status codes and identifiers. Request bodies, tokens and
 * credentials are never logged nor put into exception messages.
 */
final readonly class PayUClient implements PayUClientInterface
{
    private const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private HttpClientInterface $httpClient,
        private AccessTokenProviderInterface $accessTokenProvider,
        private LoggerInterface $logger = new NullLogger(),
        private float $timeout = 15.0,
    ) {
    }

    public function createOrder(PayUCredentials $credentials, array $order): OrderCreated
    {
        [$statusCode, $data] = $this->request($credentials, 'POST', '/api/v2_1/orders', $order);

        // PayU answers 302 Found with a JSON body. Redirects are never followed.
        if (!in_array($statusCode, [200, 201, 302], true) || self::STATUS_SUCCESS !== self::payuStatusCode($data)) {
            throw $this->apiError('Creating PayU order failed', $statusCode, $data);
        }

        $orderId = Json::string($data, 'orderId');
        $redirectUri = Json::string($data, 'redirectUri');
        if (null === $orderId || null === $redirectUri || !str_starts_with($redirectUri, 'https://')) {
            throw new PayUApiException('PayU order response is missing orderId or redirectUri.', $statusCode);
        }

        $this->logger->info('PayU order created.', ['payu_order_id' => $orderId, 'ext_order_id' => $order['extOrderId'] ?? null]);

        return new OrderCreated($orderId, $redirectUri, Json::string($data, 'extOrderId'));
    }

    public function retrieveOrder(PayUCredentials $credentials, string $orderId): PayUOrder
    {
        [$statusCode, $data] = $this->request($credentials, 'GET', '/api/v2_1/orders/' . rawurlencode($orderId));

        if (200 !== $statusCode || self::STATUS_SUCCESS !== self::payuStatusCode($data)) {
            throw $this->apiError('Retrieving PayU order failed', $statusCode, $data);
        }

        $orders = $data['orders'] ?? null;
        if (!is_array($orders)) {
            throw new PayUApiException('PayU order response has no "orders" list.', $statusCode);
        }

        foreach ($orders as $item) {
            if (!is_array($item) || Json::string($item, 'orderId') !== $orderId) {
                continue;
            }

            $status = Json::string($item, 'status');
            $amount = $item['totalAmount'] ?? null;
            $currency = Json::string($item, 'currencyCode');
            if (null === $status || !is_numeric($amount) || null === $currency) {
                throw new PayUApiException('PayU order response is incomplete.', $statusCode);
            }

            return new PayUOrder(
                orderId: $orderId,
                extOrderId: Json::string($item, 'extOrderId'),
                status: $status,
                totalAmount: (int) $amount,
                currencyCode: $currency,
                merchantPosId: Json::string($item, 'merchantPosId'),
            );
        }

        throw new PayUApiException('PayU order response does not contain the requested order.', $statusCode);
    }

    public function cancelOrder(PayUCredentials $credentials, string $orderId): void
    {
        [$statusCode, $data] = $this->request($credentials, 'DELETE', '/api/v2_1/orders/' . rawurlencode($orderId));

        if (200 !== $statusCode || self::STATUS_SUCCESS !== self::payuStatusCode($data)) {
            throw $this->apiError('Canceling PayU order failed', $statusCode, $data);
        }

        $this->logger->info('PayU order canceled.', ['payu_order_id' => $orderId]);
    }

    public function createRefund(PayUCredentials $credentials, string $orderId, array $refund): RefundCreated
    {
        [$statusCode, $data] = $this->request(
            $credentials,
            'POST',
            '/api/v2_1/orders/' . rawurlencode($orderId) . '/refunds',
            ['refund' => $refund],
        );

        if (200 !== $statusCode || self::STATUS_SUCCESS !== self::payuStatusCode($data)) {
            throw $this->apiError('Creating PayU refund failed', $statusCode, $data);
        }

        $refundData = $data['refund'] ?? null;
        if (!is_array($refundData)) {
            throw new PayUApiException('PayU refund response has no "refund" object.', $statusCode);
        }

        $refundId = Json::string($refundData, 'refundId');
        $status = Json::string($refundData, 'status');
        $amount = $refundData['amount'] ?? null;
        $currency = Json::string($refundData, 'currencyCode');
        if (null === $refundId || null === $status || !is_numeric($amount) || null === $currency) {
            throw new PayUApiException('PayU refund response is incomplete.', $statusCode);
        }

        $this->logger->info('PayU refund created.', ['payu_order_id' => $orderId, 'payu_refund_id' => $refundId, 'status' => $status]);

        return new RefundCreated($refundId, Json::string($refundData, 'extRefundId'), $status, (int) $amount, $currency);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<array-key, mixed>}
     */
    private function request(PayUCredentials $credentials, string $method, string $path, ?array $json = null, bool $retryOnUnauthorized = true): array
    {
        $token = $this->accessTokenProvider->getToken($credentials);

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'max_redirects' => 0,
            'timeout' => $this->timeout,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $credentials->environment->baseUrl() . $path, $options);
            $statusCode = $response->getStatusCode();

            if (401 === $statusCode && $retryOnUnauthorized) {
                $this->accessTokenProvider->invalidate($credentials);

                return $this->request($credentials, $method, $path, $json, false);
            }

            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                $data = [];
            }
        } catch (TransportExceptionInterface) {
            $this->logger->warning('PayU API is unreachable.', ['method' => $method, 'path' => $path]);

            throw new PayUTransportException('Could not reach PayU API.');
        } catch (HttpClientExceptionInterface) {
            throw new PayUTransportException('PayU API returned an unreadable response.');
        }

        return [$statusCode, $data];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function apiError(string $message, int $statusCode, array $data): PayUApiException
    {
        $payuStatusCode = self::payuStatusCode($data);
        $status = $data['status'] ?? null;
        $statusDesc = is_array($status) ? Json::string($status, 'statusDesc') : null;
        $codeLiteral = is_array($status) ? Json::string($status, 'codeLiteral') : null;

        $this->logger->error($message . '.', [
            'http_status' => $statusCode,
            'payu_status' => $payuStatusCode,
            'payu_code' => $codeLiteral,
        ]);

        return new PayUApiException(
            sprintf(
                '%s (HTTP %d%s%s%s).',
                $message,
                $statusCode,
                null !== $payuStatusCode ? ', ' . $payuStatusCode : '',
                null !== $codeLiteral ? '/' . $codeLiteral : '',
                null !== $statusDesc ? ': ' . mb_substr($statusDesc, 0, 200) : '',
            ),
            $statusCode,
            $payuStatusCode,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function payuStatusCode(array $data): ?string
    {
        $status = $data['status'] ?? null;

        return is_array($status) ? Json::string($status, 'statusCode') : null;
    }
}
