<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Sandbox;

use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Process\Process;
use Tests\Refberg\SyliusPayUPlugin\Integration\IntegrationTestCase;
use Tests\Refberg\SyliusPayUPlugin\Support\SandboxEnvironment;
use function array_values;
use function json_encode;
use function sleep;
use function sprintf;
use function time;

/**
 * End-to-end checks against the real PayU sandbox. Never part of CI.
 *
 * Run with:
 *   PAYU_SANDBOX=1 PAYU_SANDBOX_POS_ID=... PAYU_SANDBOX_SECOND_KEY=... \
 *   PAYU_SANDBOX_CLIENT_ID=... PAYU_SANDBOX_CLIENT_SECRET=... vendor/bin/phpunit --testsuite=sandbox
 *
 * The customer's payment is simulated by tests/Sandbox/pay.cjs (Playwright). PayU notifications
 * cannot reach a local machine, so the payment state is taken from the PayU API, like after
 * the customer returns to the shop.
 */
final class SandboxFlowTest extends IntegrationTestCase
{
    private const POLL_SECONDS = 120;

    protected function setUp(): void
    {
        if (!SandboxEnvironment::isEnabled() || null === SandboxEnvironment::credentials()) {
            self::markTestSkipped('Set PAYU_SANDBOX=1 and PAYU_SANDBOX_* credentials to run tests against the PayU sandbox.');
        }

        parent::setUp();

        // The notification URL must look public to PayU; notifications are not expected to arrive.
        $this->client->setServerParameters(['HTTP_HOST' => 'shop.example.com', 'HTTPS' => 'on']);
    }

    public function test_paid_order_is_completed_and_can_be_refunded(): void
    {
        $payment = $this->createPlacedOrder();
        $redirect = $this->payInShop();
        self::assertStringStartsWith('https://merch-prod.snd.payu.com/pay/', $redirect);

        $this->payAtPayU($redirect, 'positive');
        $payment = $this->waitForPaymentState($payment, 'completed');

        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        $orderId = PaymentDetails::of($payment)->getCompletedOrderId();
        self::assertNotNull($orderId);

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_REFUND);

        $refundRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $refundRequests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $refundRequests[0]->getState(), (string) json_encode($refundRequests[0]->getResponseData()));

        $refunds = $this->details($payment)->refunds();
        self::assertCount(1, $refunds);
        self::assertContains(array_values($refunds)[0]['status'] ?? null, ['PENDING', 'FINALIZED']);
        self::assertSame(1500, array_values($refunds)[0]['amount'] ?? null);
    }

    public function test_rejected_payment_is_not_paid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payAtPayU($this->payInShop(), 'negative');

        $payment = $this->waitForPaymentState($payment, 'failed');

        self::assertNotSame('paid', $payment->getOrder()?->getPaymentState());
    }

    public function test_cancelling_the_payment_cancels_the_payu_order(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->details($payment)->getCurrentOrderId();
        self::assertNotNull($orderId);

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $cancelRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $cancelRequests[0]->getState(), (string) json_encode($cancelRequests[0]->getResponseData()));

        $payment = $this->reloadPayment($payment);
        $method = $payment->getMethod();
        self::assertNotNull($method);
        $credentials = $this->service(CredentialsResolverInterface::class)->resolve($method);
        self::assertSame('CANCELED', $this->service(PayUClientInterface::class)->retrieveOrder($credentials, $orderId)->status);
    }

    protected function payuCredentials(): array
    {
        return SandboxEnvironment::credentials() ?? [];
    }

    private function payAtPayU(string $redirectUri, string $outcome): void
    {
        $process = new Process(['node', __DIR__ . '/pay.cjs', $redirectUri, $outcome]);
        $process->setTimeout(180);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * Opens the after-pay page (the customer coming back), which makes the plugin ask the PayU API for the state.
     */
    private function waitForPaymentState(PaymentInterface $payment, string $state): PaymentInterface
    {
        $captureRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE);
        self::assertNotEmpty($captureRequests);
        $afterPayPath = '/en_US/order/after-pay/' . $captureRequests[0]->getId();

        $deadline = time() + self::POLL_SECONDS;
        do {
            $this->client->request('GET', $afterPayPath);
            $payment = $this->reloadPayment($payment);
            if ($state === $payment->getState()) {
                return $payment;
            }

            sleep(5);
        } while (time() < $deadline);

        self::fail(sprintf(
            'Payment did not reach "%s" within %d s; it is "%s", PayU status "%s".',
            $state,
            self::POLL_SECONDS,
            (string) $payment->getState(),
            (string) PaymentDetails::of($payment)->getOrderStatus((string) PaymentDetails::of($payment)->getCurrentOrderId()),
        ));
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $this->service(StateMachineInterface::class, 'sylius_abstraction.state_machine')
            ->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class, ?string $id = null): object
    {
        $service = static::getContainer()->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
