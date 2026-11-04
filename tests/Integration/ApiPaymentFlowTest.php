<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Headless checkout through Sylius API Platform endpoints, without any shop UI.
 */
final class ApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_client_gets_the_payu_redirect_url_and_checks_status(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('capture', $created['action']);
        self::assertSame('processing', $created['state']);
        $orderId = $this->payu->lastOrderId();
        self::assertSame('https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId, self::at($created, 'responseData', 'redirect_url'));
        self::assertSame('https://app.example/payment/return', $this->payu->order($orderId)['continueUrl']);

        // Payment still unpaid; the client asks for the status after the customer returns.
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $status = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'status',
        ]);

        self::assertSame('completed', $status['state']);
        self::assertSame('completed', self::at($status, 'responseData', 'payment_state'));
        self::assertSame('COMPLETED', self::at($status, 'responseData', 'payu_status'));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_invalid_continue_url_fails_the_request_without_calling_payu(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'javascript:alert(1)'],
        ]);

        self::assertSame('failed', $created['state']);
        self::assertSame([], $this->payu->orderIds());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_action_is_not_available_to_shop_api_clients(): void
    {
        $payment = $this->createPlacedOrder();

        $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'refund',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function postPaymentRequest(array $body): array
    {
        $this->client->request(
            'POST',
            '/api/v2/shop/orders/order-token/payment-requests',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
