<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\OrderPay;

use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use function in_array;

/**
 * After returning from PayU: when PayU is still processing the payment, show the thank-you page
 * instead of offering to pay again. The payment itself stays unpaid until PayU confirms it.
 *
 * Completed and failed payments are left to the default Sylius flow.
 */
final readonly class StatusHttpResponseProvider implements HttpResponseProviderInterface
{
    private const IN_PROGRESS = [
        PayUOrder::STATUS_PENDING,
        PayUOrder::STATUS_WAITING_FOR_CONFIRMATION,
    ];

    public function __construct(private FinalUrlProviderInterface $finalUrlProvider)
    {
    }

    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState() &&
            in_array($paymentRequest->getResponseData()['payu_status'] ?? null, self::IN_PROGRESS, true);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): RedirectResponse
    {
        return new RedirectResponse($this->finalUrlProvider->getUrl(null));
    }
}
