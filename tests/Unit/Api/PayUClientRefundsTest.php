<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Api;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\AccessTokenProvider;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\PayUClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class PayUClientRefundsTest extends TestCase
{
    public function test_it_retrieves_the_refunds_of_the_order(): void
    {
        $payu = new FakePayU();
        $client = self::client($payu);
        $orderId = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'))->orderId;
        $otherOrderId = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000002-1-bbbbbbbbbbbb'))->orderId;
        $payu->setOrderStatus($orderId, 'COMPLETED');
        $payu->setOrderStatus($otherOrderId, 'COMPLETED');
        $client->createRefund(FakePayU::credentials(), $orderId, ['description' => 'Refund', 'amount' => 1500, 'currencyCode' => 'PLN', 'extRefundId' => 'r-1']);
        $client->createRefund(FakePayU::credentials(), $otherOrderId, ['description' => 'Refund', 'amount' => 1500, 'currencyCode' => 'PLN', 'extRefundId' => 'r-2']);
        $panelRefundId = $payu->createPanelRefund($orderId, 500);
        $payu->setRefundStatus($panelRefundId, 'FINALIZED');

        $refunds = $client->retrieveRefunds(FakePayU::credentials(), $orderId);

        self::assertCount(2, $refunds);
        self::assertSame('r-1', $refunds[0]->extRefundId);
        self::assertSame('PENDING', $refunds[0]->status);
        self::assertSame(1500, $refunds[0]->amount);
        self::assertSame('PLN', $refunds[0]->currencyCode);
        self::assertSame($panelRefundId, $refunds[1]->refundId);
        self::assertNull($refunds[1]->extRefundId);
        self::assertSame('FINALIZED', $refunds[1]->status);
        self::assertSame(500, $refunds[1]->amount);
    }

    public function test_order_without_refunds_has_an_empty_list(): void
    {
        $payu = new FakePayU();
        $client = self::client($payu);
        $orderId = $client->createOrder(FakePayU::credentials(), FakePayU::orderBody('000001-1-aaaaaaaaaaaa'))->orderId;

        self::assertSame([], $client->retrieveRefunds(FakePayU::credentials(), $orderId));
    }

    public function test_retrieving_the_refunds_of_an_unknown_order_fails(): void
    {
        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('Retrieving PayU refunds failed (HTTP 404, DATA_NOT_FOUND).');

        self::client(new FakePayU())->retrieveRefunds(FakePayU::credentials(), 'UNKNOWN');
    }

    public function test_response_without_the_refund_list_fails(): void
    {
        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU refund list response has no "refunds" list.');

        self::clientAnswering(['refunds' => 'none'])->retrieveRefunds(FakePayU::credentials(), 'ORDER1');
    }

    /**
     * @param array<string, mixed> $refund
     */
    #[TestWith([['status' => 'PENDING', 'amount' => '1500', 'currencyCode' => 'PLN']])]
    #[TestWith([['refundId' => '5000000001', 'amount' => '1500', 'currencyCode' => 'PLN']])]
    #[TestWith([['refundId' => '5000000001', 'status' => 'PENDING', 'amount' => 'all', 'currencyCode' => 'PLN']])]
    #[TestWith([['refundId' => '5000000001', 'status' => 'PENDING', 'amount' => '1500']])]
    public function test_incomplete_refund_in_the_list_fails(array $refund): void
    {
        $complete = ['refundId' => '5000000002', 'status' => 'FINALIZED', 'amount' => '100', 'currencyCode' => 'PLN'];

        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU refund list response is incomplete.');

        self::clientAnswering(['refunds' => [$complete, $refund]])->retrieveRefunds(FakePayU::credentials(), 'ORDER1');
    }

    public function test_non_object_refund_in_the_list_fails(): void
    {
        $this->expectException(PayUApiException::class);
        $this->expectExceptionMessage('PayU refund list response is incomplete.');

        self::clientAnswering(['refunds' => ['5000000001']])->retrieveRefunds(FakePayU::credentials(), 'ORDER1');
    }

    private static function client(FakePayU $payu): PayUClient
    {
        $httpClient = new MockHttpClient($payu);

        return new PayUClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()));
    }

    /**
     * @param array<string, mixed> $refundList
     */
    private static function clientAnswering(array $refundList): PayUClient
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'token', 'token_type' => 'bearer', 'expires_in' => 43199], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($refundList, JSON_THROW_ON_ERROR)),
        ]);

        return new PayUClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()));
    }
}
