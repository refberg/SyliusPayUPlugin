<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Config\Environment;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Notification\Notification;
use Refberg\SyliusPayUPlugin\Notification\NotificationParser;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifier;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;

final class NotificationParserTest extends TestCase
{
    private NotificationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new NotificationParser(new SignatureVerifier());
    }

    public function test_it_parses_a_signed_order_notification(): void
    {
        $body = '{"order":{"orderId":"ORDER1","extOrderId":"000001-1-abcdef012345","status":"COMPLETED"}}';

        $notification = $this->parser->parse($body, ['openpayu-signature' => [FakePayU::signatureHeader($body)]], self::credentials());

        self::assertNotNull($notification);
        self::assertSame(Notification::TYPE_ORDER, $notification->type);
        self::assertSame('ORDER1', $notification->orderId);
        self::assertSame('000001-1-abcdef012345', $notification->extOrderId);
    }

    public function test_it_parses_a_signed_refund_notification(): void
    {
        $body = '{"orderId":"ORDER1","extOrderId":"000001-1-abcdef012345","refund":{"refundId":"912","extRefundId":"r-1","amount":"1500","currencyCode":"PLN","status":"FINALIZED"}}';

        $notification = $this->parser->parse($body, ['OpenPayu-Signature' => [FakePayU::signatureHeader($body, algorithm: 'MD5')]], self::credentials());

        self::assertNotNull($notification);
        self::assertSame(Notification::TYPE_REFUND, $notification->type);
        self::assertSame('912', $notification->refundId);
        self::assertSame('r-1', $notification->extRefundId);
        self::assertSame('FINALIZED', $notification->refundStatus);
    }

    public function test_it_rejects_a_wrong_signature(): void
    {
        $body = '{"order":{"orderId":"ORDER1","status":"COMPLETED"}}';

        self::assertNull($this->parser->parse($body, ['openpayu-signature' => [FakePayU::signatureHeader($body, 'attacker-key')]], self::credentials()));
    }

    public function test_it_rejects_a_missing_signature(): void
    {
        self::assertNull($this->parser->parse('{"order":{"orderId":"ORDER1"}}', [], self::credentials()));
    }

    public function test_it_rejects_signed_garbage(): void
    {
        foreach (['not json', '[]', '{"order":{}}', '{"refund":{"status":"FINALIZED"}}', '{"something":"else"}'] as $body) {
            self::assertNull($this->parser->parse($body, ['openpayu-signature' => [FakePayU::signatureHeader($body)]], self::credentials()), $body);
        }
    }

    public function test_it_parses_the_payload_stored_by_sylius(): void
    {
        $body = '{"order":{"orderId":"ORDER1","extOrderId":"x","status":"PENDING"}}';
        $payload = ['http_request' => [
            'content' => $body,
            'headers' => ['openpayu-signature' => [FakePayU::signatureHeader($body)], 'content-type' => ['application/json']],
        ]];

        self::assertSame('ORDER1', $this->parser->parseStoredPayload($payload, self::credentials())?->orderId);
        self::assertNull($this->parser->parseStoredPayload(null, self::credentials()));
        self::assertNull($this->parser->parseStoredPayload(['http_request' => ['content' => $body, 'headers' => []]], self::credentials()));
    }

    private static function credentials(): PayUCredentials
    {
        return new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, FakePayU::CLIENT_SECRET, Environment::Sandbox);
    }
}
