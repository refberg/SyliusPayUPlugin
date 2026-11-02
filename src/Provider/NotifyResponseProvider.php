<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Provider;

use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayU expects HTTP 200 for an accepted notification. Other gateways keep the core response.
 */
final class NotifyResponseProvider implements NotifyResponseProviderInterface
{
    public function __construct(
        private readonly NotifyResponseProviderInterface $decorated,
        private readonly CredentialsResolverInterface $credentialsResolver,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        if (!$this->credentialsResolver->supports($paymentRequest->getMethod())) {
            return $this->decorated->provide($paymentRequest);
        }

        return new Response('', Response::HTTP_OK);
    }
}
