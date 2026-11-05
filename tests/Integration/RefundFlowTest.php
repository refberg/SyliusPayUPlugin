<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use function array_key_first;
use function array_values;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class RefundFlowTest extends IntegrationTestCase
{
    public function test_admin_refund_requests_a_single_full_refund_at_payu(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();

        $this->refundInAdmin($payment);

        $refundCalls = $this->payu->requests('POST', '#/refunds$#');
        self::assertCount(1, $refundCalls);
        self::assertSame('/api/v2_1/orders/' . $orderId . '/refunds', $refundCalls[0]['path']);
        $body = json_decode($refundCalls[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['description' => 'Refund for order 000000001', 'amount' => 1500, 'currencyCode' => 'PLN'], [
            'description' => self::at($body, 'refund', 'description'),
            'amount' => self::at($body, 'refund', 'amount'),
            'currencyCode' => self::at($body, 'refund', 'currencyCode'),
        ]);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertSame((string) $requests[0]->getId(), self::at($body, 'refund', 'extRefundId'));

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $refunds = PaymentDetails::of($payment)->refunds();
        self::assertCount(1, $refunds);
        self::assertSame('PENDING', array_values($refunds)[0]['status']);
    }

    public function test_refund_notification_records_the_final_status(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $this->refundInAdmin($payment);
        $refundId = (string) array_key_first($this->payu->refunds());

        $body = $this->payu->refundNotificationBody($orderId, $refundId, 'FINALIZED');
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());
        // Duplicate delivery.
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        $refunds = $this->details($payment)->refunds();
        self::assertSame('FINALIZED', array_values($refunds)[0]['status']);
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_notification_with_wrong_signature_is_rejected(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $this->refundInAdmin($payment);
        $refundId = (string) array_key_first($this->payu->refunds());

        $body = $this->payu->refundNotificationBody($orderId, $refundId, 'CANCELED');
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, FakePayU::signatureHeader($body, 'wrong'))->getStatusCode());

        self::assertSame('PENDING', array_values($this->details($payment)->refunds())[0]['status']);
    }

    #[TestWith([400, 'ERROR_VALUE_INVALID'])]
    #[TestWith([503, 'ERROR_INTERNAL'])]
    public function test_failed_refund_call_is_recorded_on_the_payment_request(int $httpStatus, string $payuStatusCode): void
    {
        $payment = $this->paidPayment();
        $this->payu->failNextRequest($httpStatus, $payuStatusCode);

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString($payuStatusCode, (string) json_encode($requests[0]->getResponseData()));
        self::assertSame([], $this->details($payment)->refunds());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_request_for_a_payment_that_was_not_refunded_in_sylius_never_reaches_payu(): void
    {
        $payment = $this->paidPayment();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_REFUND);

        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be refunded.'], $requests[0]->getResponseData());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_admin_refund_after_a_partial_panel_refund_requests_only_the_remaining_amount(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $panelRefundId = $this->payu->createPanelRefund($orderId, 500);
        $body = $this->payu->refundNotificationBody($orderId, $panelRefundId, 'FINALIZED');
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        $this->refundInAdmin($payment);

        $refundCalls = $this->payu->requests('POST', '#/refunds$#');
        self::assertCount(1, $refundCalls);
        $sent = json_decode($refundCalls[0]['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame(1000, self::at($sent, 'refund', 'amount'));

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
    }

    public function test_admin_refund_after_panel_refunds_covering_the_payment_does_not_call_payu(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $panelRefundId = $this->payu->createPanelRefund($orderId, 1500);
        $body = $this->payu->refundNotificationBody($orderId, $panelRefundId, 'PENDING');
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());

        $this->refundInAdmin($payment);

        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_payu_panel_refunds'));
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->notifyOrder($orderId);

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());

        return $payment;
    }

    /**
     * Same transition the admin "Refund" button applies.
     */
    private function refundInAdmin(PaymentInterface $payment): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();
    }
}
