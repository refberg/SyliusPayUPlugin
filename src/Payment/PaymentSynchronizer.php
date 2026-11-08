<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidatorInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Fetches one PayU order from the API, checks it against the payment and applies its state.
 */
final readonly class PaymentSynchronizer implements PaymentSynchronizerInterface
{
    public function __construct(
        private PayUClientInterface $client,
        private PayUOrderValidatorInterface $validator,
        private PaymentStateApplierInterface $stateApplier,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws PayUException
     *
     * @return string one of PaymentStateApplierInterface::RESULT_* or self::RESULT_INVALID
     */
    public function synchronize(PaymentInterface $payment, string $orderId, PayUCredentials $credentials): string
    {
        $order = $this->client->retrieveOrder($credentials, $orderId);

        $errors = $this->validator->validate($order, $payment, $credentials);
        if ([] !== $errors) {
            $this->logger->error('PayU order does not match the Sylius payment; state not changed.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $orderId,
                'errors' => $errors,
            ]);

            return self::RESULT_INVALID;
        }

        return $this->stateApplier->apply($payment, $order);
    }
}
