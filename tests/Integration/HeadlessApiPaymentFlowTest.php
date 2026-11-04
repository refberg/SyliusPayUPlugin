<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPayUPlugin\HeadlessApplication\HeadlessKernel;

final class HeadlessApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_checkout_is_paid_by_a_signed_notification_without_the_shop(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame('processing', $created['state'], (string) $this->client->getResponse()->getContent());
        $orderId = $this->payu->lastOrderId();
        self::assertSame('http://localhost/payment-methods/PAYU', $this->payu->order($orderId)['notifyUrl']);

        $this->payu->setOrderStatus($orderId, 'COMPLETED');

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_capture_without_continue_url_fails_without_calling_payu(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
        ]);

        self::assertSame('failed', $created['state']);
        self::assertIsString(self::at($created, 'responseData', 'error'));
        self::assertStringContainsString('continue_url', self::at($created, 'responseData', 'error'));
        self::assertSame([], $this->payu->orderIds());
    }

    protected static function getKernelClass(): string
    {
        return HeadlessKernel::class;
    }
}
