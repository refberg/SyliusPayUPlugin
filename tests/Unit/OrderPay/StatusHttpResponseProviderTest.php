<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\OrderPay;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;

final class StatusHttpResponseProviderTest extends TestCase
{
    #[TestWith([PayUOrder::STATUS_PENDING])]
    #[TestWith([PayUOrder::STATUS_WAITING_FOR_CONFIRMATION])]
    public function test_payment_still_processed_by_payu_shows_the_thank_you_page(string $payuStatus): void
    {
        $provider = $this->provider();
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, $payuStatus);

        self::assertTrue($provider->supports(new Request(), $paymentRequest));
        self::assertSame('/en_US/order/thank-you', $provider->getResponse(new Request(), $paymentRequest)->getTargetUrl());
    }

    #[TestWith([PayUOrder::STATUS_COMPLETED])]
    #[TestWith([PayUOrder::STATUS_CANCELED])]
    #[TestWith([PayUOrder::STATUS_NEW])]
    #[TestWith([null])]
    public function test_finished_or_unknown_payu_status_is_left_to_sylius(?string $payuStatus): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, $payuStatus);

        self::assertFalse($this->provider()->supports(new Request(), $paymentRequest));
    }

    #[TestWith([PaymentRequestInterface::STATE_NEW])]
    #[TestWith([PaymentRequestInterface::STATE_PROCESSING])]
    #[TestWith([PaymentRequestInterface::STATE_FAILED])]
    public function test_request_that_is_not_completed_is_left_to_sylius(string $state): void
    {
        $paymentRequest = $this->paymentRequest($state, PayUOrder::STATUS_PENDING);

        self::assertFalse($this->provider()->supports(new Request(), $paymentRequest));
    }

    private function provider(): StatusHttpResponseProvider
    {
        $finalUrlProvider = self::createStub(FinalUrlProviderInterface::class);
        $finalUrlProvider->method('getUrl')->willReturn('/en_US/order/thank-you');

        return new StatusHttpResponseProvider($finalUrlProvider);
    }

    private function paymentRequest(string $state, ?string $payuStatus): PaymentRequestInterface
    {
        $paymentRequest = self::createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getState')->willReturn($state);
        $paymentRequest->method('getResponseData')->willReturn(null === $payuStatus ? [] : ['payu_status' => $payuStatus]);

        return $paymentRequest;
    }
}
