<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use function sprintf;

/**
 * Creates a PayU order and stores the redirect URL in the payment request response data.
 *
 * It never completes the payment: only a verified notification or a direct API query can.
 */
final readonly class CapturePaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PayUClientInterface $client,
        private OrderRequestFactoryInterface $orderRequestFactory,
        private ExtOrderIdGenerator $extOrderIdGenerator,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        // A processing request already has a PayU order: re-sending the customer there is enough.
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
            $this->paymentLocker->lock($payment);
            if (PaymentInterface::STATE_NEW !== $payment->getState()) {
                $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be paid.', (string) $payment->getState()));

                return;
            }

            $extOrderId = $this->extOrderIdGenerator->generate($payment, $paymentRequest);
            $orderRequest = $this->orderRequestFactory->create($paymentRequest, $payment, $credentials, $extOrderId);
            $created = $this->client->createOrder($credentials, $orderRequest);
        } catch (PayUException|InvalidConfigurationException|InvalidArgumentException $exception) {
            $this->logger->warning('Could not create PayU order.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $details->addOrder($created->orderId, $extOrderId, (int) $payment->getAmount(), (string) $payment->getCurrencyCode());
        $details->save();

        $paymentRequest->setResponseData([
            'redirect_url' => $created->redirectUri,
            'order_id' => $created->orderId,
            'ext_order_id' => $extOrderId,
        ]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }
}
