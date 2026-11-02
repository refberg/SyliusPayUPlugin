<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;

final class CancelAndExternalRefundTest extends IntegrationTestCase
{
    public function test_cancelling_the_payment_in_sylius_cancels_the_open_payu_order(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertCount(1, $this->payu->requests('DELETE', '#^/api/v2_1/orders/' . $orderId . '$#'));
        self::assertSame('CANCELED', $this->payu->order($orderId)['status']);
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
        self::assertSame('CANCELED', $this->details($payment)->getOrderStatus($orderId));

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());

        // The late CANCELED notification changes nothing.
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
    }

    public function test_payment_completed_before_the_cancel_reached_payu_is_flagged(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        // Paid at PayU, notification not delivered yet.
        $this->payu->setOrderStatus($orderId, 'COMPLETED');

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());

        $this->notifyOrder($orderId);
        $payment = $this->reloadPayment($payment);
        self::assertSame('cancelled', $payment->getState());
        self::assertSame(PaymentDetails::WARNING_LATE_PAYMENT, PaymentDetails::of($payment)->warnings()[0]['type'] ?? null);
    }

    public function test_full_refund_made_in_payu_panel_marks_the_payment_refunded(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $refundId = $this->payu->createPanelRefund($orderId, 1500);

        $body = $this->payu->refundNotificationBody($orderId, $refundId, 'FINALIZED');
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        // Sylius must not ask PayU for a second refund.
        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
        self::assertSame(1500, $this->details($payment)->finalizedRefundAmount());
    }

    public function test_partial_refund_made_in_payu_panel_is_only_recorded(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $refundId = $this->payu->createPanelRefund($orderId, 500);

        $body = $this->payu->refundNotificationBody($orderId, $refundId, 'FINALIZED');
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
        self::assertSame(500, $this->details($payment)->finalizedRefundAmount());

        $second = $this->payu->createPanelRefund($orderId, 1000);
        $body = $this->payu->refundNotificationBody($orderId, $second, 'FINALIZED');
        $this->notify($body, FakePayU::signatureHeader($body));

        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
    }

    public function test_pending_panel_refund_does_not_change_the_payment(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->payu->lastOrderId();
        $refundId = $this->payu->createPanelRefund($orderId, 1500);

        $body = $this->payu->refundNotificationBody($orderId, $refundId, 'CANCELED');
        $this->notify($body, FakePayU::signatureHeader($body));

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
        self::assertSame(0, $this->details($payment)->finalizedRefundAmount());
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->notifyOrder($orderId);
        self::assertSame('completed', $this->reloadPayment($payment)->getState());

        return $payment;
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
