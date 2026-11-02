<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Api;

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
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;

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
        $created = $this->client()->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));

        self::assertSame($this->payu->lastOrderId(), $created->orderId);
        self::assertStringStartsWith('https://merch-prod.snd.payu.com/pay/', $created->redirectUri);
        self::assertCount(1, $this->payu->requests('POST', '#^/api/v2_1/orders$#'));
        self::assertCount(0, $this->payu->requests('GET', '#/pay/#'));
    }

    public function test_it_sends_the_bearer_token_and_caches_it(): void
    {
        $client = $this->client();
        $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
        $client->retrieveOrder(self::credentials(), $this->payu->lastOrderId());

        self::assertCount(1, $this->payu->requests('POST', '#oauth/authorize#'));
        $order = $this->payu->requests('GET')[0];
        self::assertSame('Bearer fake-token-1', $order['headers']['authorization']);
    }

    public function test_it_refreshes_an_expired_token_once(): void
    {
        $this->client()->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));

        // A different FakePayU instance does not know the cached token, like PayU after token expiry.
        $otherPayU = new FakePayU();
        $client = $this->client($otherPayU);
        $client->createOrder(self::credentials(), self::orderBody('000001-1-bbbbbbbbbbbb'));

        self::assertCount(1, $otherPayU->requests('POST', '#oauth/authorize#'));
        self::assertCount(2, $otherPayU->requests('POST', '#^/api/v2_1/orders$#'));
    }

    public function test_it_reports_wrong_oauth_credentials_without_leaking_the_secret(): void
    {
        $credentials = new PayUCredentials('300046', FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, 'wrong-secret-value', Environment::Sandbox);

        try {
            $this->client()->createOrder($credentials, self::orderBody('000001-1-aaaaaaaaaaaa'));
            self::fail('Expected an authentication exception.');
        } catch (PayUAuthenticationException $exception) {
            self::assertStringNotContainsString('wrong-secret-value', $exception->getMessage());
        }
    }

    public function test_it_maps_api_errors(): void
    {
        $this->payu->failNextRequest(400, 'ERROR_VALUE_INVALID');

        try {
            $this->client()->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertSame(400, $exception->getHttpStatusCode());
            self::assertSame('ERROR_VALUE_INVALID', $exception->getPayUStatusCode());
            self::assertFalse($exception->isTransient());
        }
    }

    public function test_server_errors_are_transient(): void
    {
        $this->payu->failNextRequest(503);

        try {
            $this->client()->retrieveOrder(self::credentials(), 'X');
            self::fail('Expected an API exception.');
        } catch (PayUApiException $exception) {
            self::assertTrue($exception->isTransient());
        }
    }

    public function test_it_rejects_a_duplicated_ext_order_id(): void
    {
        $client = $this->client();
        $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('ERROR_ORDER_NOT_UNIQUE');

        $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
    }

    public function test_it_retrieves_an_order(): void
    {
        $client = $this->client();
        $created = $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->setOrderStatus($created->orderId, 'COMPLETED');

        $order = $client->retrieveOrder(self::credentials(), $created->orderId);

        self::assertSame('COMPLETED', $order->status);
        self::assertSame(1500, $order->totalAmount);
        self::assertSame('PLN', $order->currencyCode);
        self::assertSame('000001-1-aaaaaaaaaaaa', $order->extOrderId);
        self::assertSame(FakePayU::POS_ID, $order->merchantPosId);
    }

    public function test_it_creates_a_refund(): void
    {
        $client = $this->client();
        $created = $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->setOrderStatus($created->orderId, 'COMPLETED');

        $refund = $client->createRefund(self::credentials(), $created->orderId, ['description' => 'Refund', 'amount' => 1500, 'currencyCode' => 'PLN', 'extRefundId' => 'r-1']);

        self::assertSame('PENDING', $refund->status);
        self::assertSame(1500, $refund->amount);
        self::assertSame('r-1', $refund->extRefundId);
    }

    public function test_transport_errors_are_wrapped(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection timed out']));
        $client = new PayUClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()));

        $this->expectException(PayUTransportException::class);

        $client->retrieveOrder(self::credentials(), 'X');
    }

    public function test_it_refuses_to_call_any_host_but_the_sandbox(): void
    {
        $credentials = new PayUCredentials('1', 'k', 'c', 's', Environment::Production);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('secure.payu.com');

        $this->client()->retrieveOrder($credentials, 'X');
    }

    public function test_logs_never_contain_secrets_or_tokens(): void
    {
        $client = $this->client();
        $created = $client->createOrder(self::credentials(), self::orderBody('000001-1-aaaaaaaaaaaa'));
        $this->payu->failNextRequest(500);

        try {
            $client->retrieveOrder(self::credentials(), $created->orderId);
        } catch (PayUApiException) {
        }

        $serialized = json_encode($this->logger->records, \JSON_THROW_ON_ERROR);
        self::assertNotSame('[]', $serialized);
        foreach ([FakePayU::CLIENT_SECRET, FakePayU::SECOND_KEY, 'fake-token-1'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    private function client(?FakePayU $payu = null): PayUClient
    {
        $httpClient = new MockHttpClient($payu ?? $this->payu);

        return new PayUClient($httpClient, new AccessTokenProvider($httpClient, $this->cache), $this->logger);
    }

    private static function credentials(): PayUCredentials
    {
        return new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, FakePayU::CLIENT_SECRET, Environment::Sandbox);
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderBody(string $extOrderId): array
    {
        return [
            'notifyUrl' => 'https://shop.example/payment-methods/PAYU',
            'continueUrl' => 'https://shop.example/after-pay',
            'customerIp' => '127.0.0.1',
            'merchantPosId' => FakePayU::POS_ID,
            'description' => 'Order 000001',
            'currencyCode' => 'PLN',
            'totalAmount' => '1500',
            'extOrderId' => $extOrderId,
            'products' => [['name' => 'Order 000001', 'unitPrice' => '1500', 'quantity' => '1']],
        ];
    }
}
