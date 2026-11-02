<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Provider;

use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Notification\Notification;
use Refberg\SyliusPayUPlugin\Notification\NotificationParser;
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Finds the payment a PayU notification belongs to.
 *
 * Runs before Sylius stores anything, so notifications with a missing or wrong signature
 * are rejected with HTTP 400 and never create a payment request.
 */
final readonly class PayUNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param RepositoryInterface<PaymentInterface> $paymentRepository
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParser $notificationParser,
        private ExtOrderIdGenerator $extOrderIdGenerator,
        private RepositoryInterface $paymentRepository,
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return $this->credentialsResolver->supports($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        try {
            $credentials = $this->credentialsResolver->resolve($paymentMethod);
        } catch (InvalidConfigurationException) {
            throw new ServiceUnavailableHttpException(null, 'PayU payment method is not configured.');
        }

        $notification = $this->notificationParser->parse($request->getContent(), $request->headers->all(), $credentials);
        if (!$notification instanceof Notification) {
            throw new BadRequestHttpException('Invalid PayU notification signature or body.');
        }

        $payment = $this->findPayment($notification);
        if (!$payment instanceof PaymentInterface || $payment->getMethod() !== $paymentMethod || !PaymentDetails::of($payment)->hasOrder($notification->orderId)) {
            throw new NotFoundHttpException('No payment matches this PayU notification.');
        }

        return $payment;
    }

    private function findPayment(Notification $notification): ?PaymentInterface
    {
        $paymentId = null !== $notification->extOrderId ? $this->extOrderIdGenerator->extractPaymentId($notification->extOrderId) : null;
        if (null !== $paymentId) {
            return $this->paymentRepository->find($paymentId);
        }

        // Refund notifications carry our extRefundId, which is the refund payment request hash.
        if (Notification::TYPE_REFUND === $notification->type && null !== $notification->extRefundId && 1 === preg_match('/^[0-9a-f-]{36}$/', $notification->extRefundId)) {
            $paymentRequest = $this->paymentRequestRepository->find($notification->extRefundId);

            return $paymentRequest?->getPayment();
        }

        return null;
    }
}
