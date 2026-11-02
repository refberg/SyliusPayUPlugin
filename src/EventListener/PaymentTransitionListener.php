<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\EventListener;

use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Turns a completed "sylius_payment" transition of a PayU payment into a payment request
 * (e.g. "refund" → ACTION_REFUND, "cancel" → ACTION_CANCEL) and dispatches it.
 */
final class PaymentTransitionListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly CredentialsResolverInterface $credentialsResolver,
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
        private readonly string $action,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $method = $payment->getMethod();
        if (null === $method || !$this->credentialsResolver->supports($method)) {
            return;
        }

        $existing = $this->paymentRequestRepository->findOneByActionPaymentAndMethod($this->action, $payment, $method);
        if (null !== $existing) {
            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $method);
        $paymentRequest->setAction($this->action);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);
    }
}
