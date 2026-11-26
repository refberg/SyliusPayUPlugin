<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use function in_array;
use function is_string;

/**
 * Asks PayU to cancel the order of a capture request that Sylius cancelled (e.g. the customer switched
 * to another payment method), so the customer cannot pay it any more.
 *
 * It runs inside a Doctrine flush, so it writes nothing: PayU answers 200 even for an order it did not cancel,
 * and the order state is changed only by a notification or a status check.
 */
final readonly class CaptureCancelledListener
{
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private PayUClientInterface $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $paymentRequest = $event->getSubject();
        if (
            !$paymentRequest instanceof PaymentRequestInterface ||
            !in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
        ) {
            return;
        }

        $orderId = $paymentRequest->getResponseData()['order_id'] ?? null;
        $payment = $paymentRequest->getPayment();
        if (
            !is_string($orderId) ||
            PayUOrder::STATUS_NEW !== PaymentDetails::of($payment)->getOrderStatus($orderId) ||
            !$this->credentialsResolver->supports($paymentRequest->getMethod())
        ) {
            return;
        }

        try {
            $this->client->cancelOrder($this->credentialsResolver->resolve($paymentRequest->getMethod()), $orderId);
        } catch (PayUException|InvalidConfigurationException $exception) {
            $this->logger->warning('Could not cancel the PayU order of a cancelled payment request; check it in the PayU panel.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $orderId,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $this->logger->info('Requested cancellation of the PayU order of a cancelled payment request.', [
            'payment_id' => $payment->getId(),
            'payu_order_id' => $orderId,
        ]);
    }
}
