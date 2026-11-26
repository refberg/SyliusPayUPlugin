<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Api;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\AccessTokenProvider;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUAuthenticationException;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUTransportException;
use Refberg\SyliusPayUPlugin\Api\PayUClient;
use Refberg\SyliusPayUPlugin\Config\Environment;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;
use function json_encode;
use function str_contains;
use function str_repeat;
use const JSON_THROW_ON_ERROR;

final class PayUClientTest extends TestCase
{
    private FakePayU $payu;

    private ArrayAdapter $cache;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->payu = new FakePayU();
        $this->cache = new ArrayAdapter();
        $this->logger = new MemoryLogger();
    }

    public function test_it_creates_an_order_without_following_the_redirect(): void
    {
        $created = $this->client()->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));

        self::assertSame($this->payu->lastOrderId(), $created->orderId);
        self::assertStringStartsWith('https://merch-prod.snd.payu.com/pay/', $created->redirectUri);
        self::assertCount(1, $this->payu->requests('POST', '#^/api/v2_1/orders$#'));
        self::assertCount(0, $this->payu->requests('GET', '#/pay/#'));
    }

    public function test_it_sends_the_bearer_token_and_caches_it(): void
    {
        $client = $this->client();
        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $client->retrieveOrder(FakePayU::credentials(), $this->payu->lastOrderId());

        self::assertCount(1, $this->payu->requests('POST', '#oauth/authorize#'));
        $order = $this->payu->requests('GET')[0];
        self::assertSame('Bearer fake-token-1', $order['headers']['authorization']);
    }

    public function test_it_refreshes_an_expired_token_once(): void
    {
        $this->client()->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));

        // A different FakePayU instance does not know the cached token, like PayU after token expiry.
        $otherPayU = new FakePayU();
        $client = $this->client($otherPayU);
        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-bbbbbbbbbbbb'));

        self::assertCount(1, $otherPayU->requests('POST', '#oauth/authorize#'));
        self::assertCount(2, $otherPayU->requests('POST', '#^/api/v2_1/orders$#'));
    }

    public function test_it_reports_wrong_oauth_credentials_without_leaking_the_secret(): void
    {
        $credentials = new PayUCredentials('300046', FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, 'wrong-secret-value', Environment::Sandbox);

        try {
            $this->client()->createOrder($credentials, FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
            self::fail('Expected an authentication exception.');
        } catch (PayUAuthenticationException $exception) {
            self::assertStringNotContainsString('wrong-secret-value', $exception->getMessage());
        }
    }

    public function test_it_maps_api_errors(): void
    {
        $this->payu->failNextRequest(400, 'ERROR_VALUE_INVALID');

        try {
            $this->client()->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertSame(400, $exception->getHttpStatusCode());
            self::assertSame('ERROR_VALUE_INVALID', $exception->getPayUStatusCode());
            self::assertFalse($exception->isTransient());
        }
    }

    #[TestWith([503])]
    #[TestWith([500])]
    #[TestWith([429])]
    #[TestWith([408])]
    public function test_temporary_errors_are_transient(int $httpStatus): void
    {
        $this->payu->failNextRequest($httpStatus);

        try {
            $this->client()->retrieveOrder(FakePayU::credentials(), 'X');
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertTrue($exception->isTransient());
        }
    }

    public function test_error_message_contains_the_payu_code_literal(): void
    {
        $this->payu->failNextRequest(400, 'OPENPAYU_BUSINESS_ERROR', 'TRANS_NOT_ENDED');

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('OPENPAYU_BUSINESS_ERROR/TRANS_NOT_ENDED');

        $this->client()->createRefund(FakePayU::credentials(), 'X', ['description' => 'Refund']);
    }

    public function test_it_rejects_a_duplicated_ext_order_id(): void
    {
        $client = $this->client();
        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('ERROR_ORDER_NOT_UNIQUE');

        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
    }

    public function test_it_retrieves_an_order(): void
    {
        $client = $this->client();
        $created = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->setOrderStatus($created->orderId, 'COMPLETED');

        $order = $client->retrieveOrder(FakePayU::credentials(), $created->orderId);

        self::assertSame('COMPLETED', $order->status);
        self::assertSame(1500, $order->totalAmount);
        self::assertSame('PLN', $order->currencyCode);
        self::assertSame('000001-1-aaaaaaaaaaaa', $order->extOrderId);
        self::assertSame(FakePayU::POS_ID, $order->merchantPosId);
    }

    public function test_it_creates_a_refund(): void
    {
        $client = $this->client();
        $created = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->setOrderStatus($created->orderId, 'COMPLETED');

        $refund = $client->createRefund(FakePayU::credentials(), $created->orderId, ['description' => 'Refund', 'amount' => 1500, 'currencyCode' => 'PLN', 'extRefundId' => 'r-1']);

        self::assertSame('PENDING', $refund->status);
        self::assertSame(1500, $refund->amount);
        self::assertSame('r-1', $refund->extRefundId);
    }

    public function test_cancel_succeeds_for_an_open_and_a_completed_order_but_cancels_only_the_open_one(): void
    {
        $client = $this->client();
        $open = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $paid = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-bbbbbbbbbbbb'));
        $this->payu->setOrderStatus($paid->orderId, 'COMPLETED');

        $client->cancelOrder(FakePayU::credentials(), $open->orderId);
        $client->cancelOrder(FakePayU::credentials(), $paid->orderId);

        self::assertSame('CANCELED', $this->payu->order($open->orderId)['status']);
        self::assertSame('COMPLETED', $this->payu->order($paid->orderId)['status']);
    }

    public function test_cancel_of_an_unknown_order_fails(): void
    {
        $this->expectException(PayUApiException::class);

        $this->client()->cancelOrder(FakePayU::credentials(), 'UNKNOWN');
    }

    public function test_cancel_answered_with_http_200_without_success_status_fails(): void
    {
        $client = $this->client();
        $created = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->failNextRequest(200, 'ERROR_VALUE_INVALID');

        $this->expectException(PayUApiException::class);

        $client->cancelOrder(FakePayU::credentials(), $created->orderId);
    }

    public function test_transport_errors_are_wrapped(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection timed out']));
        $client = new PayUClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()));

        $this->expectException(PayUTransportException::class);

        $client->retrieveOrder(FakePayU::credentials(), 'X');
    }

    public function test_it_refuses_to_call_any_host_but_the_sandbox(): void
    {
        $credentials = new PayUCredentials('1', 'k', 'c', 's', Environment::Production);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('secure.payu.com');

        $this->client()->retrieveOrder($credentials, 'X');
    }

    public function test_logs_never_contain_secrets_or_tokens(): void
    {
        $client = $this->client();
        $created = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->failNextRequest(500);

        try {
            $client->retrieveOrder(FakePayU::credentials(), $created->orderId);
        } catch (PayUApiException) {
        }

        $serialized = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertNotSame('[]', $serialized);
        foreach ([FakePayU::CLIENT_SECRET, FakePayU::SECOND_KEY, 'fake-token-1'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    #[TestWith([200])]
    #[TestWith([201])]
    public function test_order_creation_accepts_http_200_and_201(int $httpStatus): void
    {
        $client = $this->clientAnswering(self::response($httpStatus, ['status' => ['statusCode' => 'SUCCESS'], 'orderId' => 'ORDER1', 'redirectUri' => 'https://merch-prod.snd.payu.com/pay/?orderId=ORDER1']));

        self::assertSame('ORDER1', $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'))->orderId);
    }

    public function test_order_creation_without_success_status_fails(): void
    {
        $client = $this->clientAnswering(self::response(302, ['status' => ['statusCode' => 'ERROR_VALUE_INVALID'], 'orderId' => 'ORDER1', 'redirectUri' => 'https://merch-prod.snd.payu.com/pay/?orderId=ORDER1']));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('Creating PayU order failed (HTTP 302, ERROR_VALUE_INVALID).');

        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOrderCreatedResponses(): iterable
    {
        yield 'missing orderId' => [['status' => ['statusCode' => 'SUCCESS'], 'redirectUri' => 'https://merch-prod.snd.payu.com/pay/']];
        yield 'plain http redirect' => [['status' => ['statusCode' => 'SUCCESS'], 'orderId' => 'ORDER1', 'redirectUri' => 'http://merch-prod.snd.payu.com/pay/']];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidOrderCreatedResponses')]
    public function test_order_response_without_order_id_or_with_http_redirect_fails(array $data): void
    {
        $client = $this->clientAnswering(self::response(302, $data));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU order response is missing orderId or redirectUri.');

        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
    }

    public function test_retrieve_order_error_reports_the_payu_status(): void
    {
        $client = $this->client();
        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->failNextRequest(200, 'DATA_NOT_FOUND');

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('Retrieving PayU order failed (HTTP 200, DATA_NOT_FOUND).');

        $client->retrieveOrder(FakePayU::credentials(), $this->payu->lastOrderId());
    }

    public function test_response_with_only_another_order_fails(): void
    {
        $client = $this->clientAnswering(self::response(200, ['status' => ['statusCode' => 'SUCCESS'], 'orders' => [self::orderItem(['orderId' => 'OTHER'])]]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU order response does not contain the requested order.');

        $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
    }

    public function test_response_without_orders_fails(): void
    {
        $client = $this->clientAnswering(self::response(200, ['status' => ['statusCode' => 'SUCCESS']]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU order response has no "orders" list.');

        $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteOrders(): iterable
    {
        yield 'missing status' => [['status' => null]];
        yield 'non-numeric amount' => [['totalAmount' => 'abc']];
        yield 'missing currency' => [['currencyCode' => null]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('incompleteOrders')]
    public function test_incomplete_order_fails(array $override): void
    {
        $client = $this->clientAnswering(self::response(200, ['status' => ['statusCode' => 'SUCCESS'], 'orders' => [self::orderItem($override)]]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU order response is incomplete.');

        $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
    }

    public function test_refund_without_success_status_fails(): void
    {
        $client = $this->client();
        $created = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->failNextRequest(200, 'ERROR_VALUE_INVALID');

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('Creating PayU refund failed (HTTP 200, ERROR_VALUE_INVALID).');

        $client->createRefund(FakePayU::credentials(), $created->orderId, ['description' => 'Refund']);
    }

    public function test_refund_response_without_refund_fails(): void
    {
        $client = $this->clientAnswering(self::response(200, ['status' => ['statusCode' => 'SUCCESS']]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU refund response has no "refund" object.');

        $client->createRefund(FakePayU::credentials(), 'ORDER1', ['description' => 'Refund']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteRefunds(): iterable
    {
        yield 'missing refundId' => [['refundId' => null]];
        yield 'missing status' => [['status' => null]];
        yield 'non-numeric amount' => [['amount' => 'abc']];
        yield 'missing currency' => [['currencyCode' => null]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('incompleteRefunds')]
    public function test_incomplete_refund_response_fails(array $override): void
    {
        $refund = [...['refundId' => '5000001', 'status' => 'PENDING', 'amount' => '1500', 'currencyCode' => 'PLN'], ...$override];
        $client = $this->clientAnswering(self::response(200, ['status' => ['statusCode' => 'SUCCESS'], 'refund' => $refund]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU refund response is incomplete.');

        $client->createRefund(FakePayU::credentials(), 'ORDER1', ['description' => 'Refund']);
    }

    public function test_requests_disable_redirects_and_set_timeout_and_accept(): void
    {
        $apiOptions = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$apiOptions): ResponseInterface {
            /** @var array<string, mixed> $options */
            if (str_contains($url, '/api/')) {
                $apiOptions[] = $options;
            }

            return ($this->payu)($method, $url, $options);
        });
        $client = new PayUClient($httpClient, new AccessTokenProvider($httpClient, $this->cache), $this->logger, 7.5);

        $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'));

        self::assertCount(1, $apiOptions);
        self::assertSame(0, $apiOptions[0]['max_redirects'] ?? null);
        self::assertSame(7.5, $apiOptions[0]['timeout'] ?? null);
        self::assertSame('application/json', $this->payu->requests('POST', '#^/api/v2_1/orders$#')[0]['headers']['accept'] ?? null);
    }

    public function test_persistent_401_is_retried_only_once(): void
    {
        $apiCalls = 0;
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$apiCalls): MockResponse {
            if (str_contains($url, 'oauth/authorize')) {
                return self::response(200, ['access_token' => 'token', 'expires_in' => 3600]);
            }
            if (++$apiCalls > 3) {
                throw new LogicException('The 401 retry does not stop.');
            }

            return self::response(401, ['error' => 'invalid_token']);
        });
        $client = new PayUClient($httpClient, new AccessTokenProvider($httpClient, $this->cache));

        try {
            $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertSame(401, $exception->getHttpStatusCode());
        }

        self::assertSame(2, $apiCalls);
    }

    public function test_error_message_contains_the_status_description_truncated_to_200_characters(): void
    {
        $client = $this->clientAnswering(self::response(400, ['status' => ['statusCode' => 'ERROR_VALUE_INVALID', 'statusDesc' => 'a' . str_repeat('ż', 250)]]));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('Retrieving PayU order failed (HTTP 400, ERROR_VALUE_INVALID: a' . str_repeat('ż', 199) . ').');

        $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
    }

    public function test_transport_error_after_authorization_is_wrapped(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['error' => 'Connection timed out']));

        $this->expectException(PayUTransportException::class);
        $this->expectExceptionMessage('Could not reach PayU API.');

        $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
    }

    public function test_unreadable_body_is_mapped_to_an_api_error(): void
    {
        $client = $this->clientAnswering(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]));

        try {
            $client->retrieveOrder(FakePayU::credentials(), 'ORDER1');
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertSame('Retrieving PayU order failed (HTTP 502).', $exception->getMessage());
            self::assertTrue($exception->isTransient());
        }
    }

    public function test_oauth_failure_throws(): void
    {
        $credentials = new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, 'wrong-secret-value', Environment::Sandbox);

        $this->expectException(PayUAuthenticationException::class);
        $this->expectExceptionMessage('PayU OAuth authorization failed (HTTP 401). Check the OAuth client ID and secret.');

        $this->client()->retrieveOrder($credentials, 'ORDER1');
    }

    public function test_empty_token_is_rejected(): void
    {
        $httpClient = new MockHttpClient(self::response(200, ['access_token' => '', 'expires_in' => 3600]));

        $this->expectException(PayUAuthenticationException::class);
        $this->expectExceptionMessage('PayU OAuth response did not contain an access token.');

        (new AccessTokenProvider($httpClient, $this->cache))->getToken(FakePayU::credentials());
    }

    public function test_unreadable_oauth_response_is_an_authentication_error(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('<html>Maintenance</html>', ['http_code' => 200]));

        $this->expectException(PayUAuthenticationException::class);
        $this->expectExceptionMessage('PayU OAuth endpoint returned an unreadable response.');

        (new AccessTokenProvider($httpClient, $this->cache))->getToken(FakePayU::credentials());
    }

    public function test_tokens_are_cached_per_environment_and_client(): void
    {
        $authorizations = 0;
        $httpClient = new MockHttpClient(static function () use (&$authorizations): MockResponse {
            return self::response(200, ['access_token' => 'token-' . ++$authorizations, 'expires_in' => 3600]);
        });
        $provider = new AccessTokenProvider($httpClient, $this->cache);
        $sandbox = FakePayU::credentials();

        self::assertSame('token-1', $provider->getToken($sandbox));
        self::assertSame('token-2', $provider->getToken(new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, FakePayU::CLIENT_SECRET, Environment::Production)));
        self::assertSame('token-3', $provider->getToken(new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, 'other-client', FakePayU::CLIENT_SECRET, Environment::Sandbox)));
        self::assertSame('token-4', $provider->getToken(new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, 'rotated-secret', Environment::Sandbox)));
        self::assertSame('token-1', $provider->getToken($sandbox));
        self::assertSame(4, $authorizations);
    }

    public function test_oauth_request_disables_redirects_and_sets_accept_and_timeout(): void
    {
        $oauthOptions = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$oauthOptions): ResponseInterface {
            /** @var array<string, mixed> $options */
            if (str_contains($url, 'oauth/authorize')) {
                $oauthOptions[] = $options;
            }

            return ($this->payu)($method, $url, $options);
        });

        (new AccessTokenProvider($httpClient, $this->cache, 7.5))->getToken(FakePayU::credentials());

        self::assertCount(1, $oauthOptions);
        self::assertSame(0, $oauthOptions[0]['max_redirects'] ?? null);
        self::assertSame(7.5, $oauthOptions[0]['timeout'] ?? null);
        self::assertSame('application/json', $this->payu->requests('POST', '#oauth/authorize#')[0]['headers']['accept'] ?? null);
    }

    public function test_token_expires_60_seconds_before_expires_in(): void
    {
        $this->assertTokenExpiresAfter(['access_token' => 'token', 'expires_in' => 300], 240);
    }

    public function test_token_without_expires_in_expires_after_one_second(): void
    {
        $this->assertTokenExpiresAfter(['access_token' => 'token'], 1);
    }

    private function client(?FakePayU $payu = null): PayUClient
    {
        $httpClient = new MockHttpClient($payu ?? $this->payu);

        return new PayUClient($httpClient, new AccessTokenProvider($httpClient, $this->cache), $this->logger);
    }

    private function clientAnswering(MockResponse $apiResponse): PayUClient
    {
        $httpClient = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'oauth/authorize')
            ? self::response(200, ['access_token' => 'token', 'expires_in' => 3600])
            : $apiResponse);

        return new PayUClient($httpClient, new AccessTokenProvider($httpClient, $this->cache), $this->logger);
    }

    /**
     * @param array<string, mixed> $oauthResponse
     */
    private function assertTokenExpiresAfter(array $oauthResponse, int $expectedSeconds): void
    {
        $item = $this->createMock(ItemInterface::class);
        $item->expects(self::once())->method('expiresAfter')->with($expectedSeconds);
        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $token = (new AccessTokenProvider(new MockHttpClient(self::response(200, $oauthResponse)), $cache))->getToken(FakePayU::credentials());

        self::assertSame('token', $token);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function response(int $httpStatus, array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $httpStatus]);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function orderItem(array $override = []): array
    {
        return [...['orderId' => 'ORDER1', 'extOrderId' => '000001-1-aaaaaaaaaaaa', 'status' => 'COMPLETED', 'totalAmount' => '1500', 'currencyCode' => 'PLN'], ...$override];
    }
}
