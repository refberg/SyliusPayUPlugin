<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Order;

use InvalidArgumentException;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function array_filter;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function sprintf;
use function strtolower;
use function substr;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

/**
 * Builds the body of POST /api/v2_1/orders from a Sylius payment.
 *
 * Amount and currency always come from the Sylius payment, never from the client.
 * The order is sent as a single line so the PayU total always matches the payment amount
 * (discounts, shipping and taxes included).
 */
final readonly class OrderRequestFactory implements OrderRequestFactoryInterface
{
    public const PAYLOAD_CONTINUE_URL = 'continue_url';

    private const NOTIFY_ROUTE = 'sylius_payment_method_notify';

    private const FALLBACK_CUSTOMER_IP = '127.0.0.1';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ?UrlProviderInterface $afterPayUrlProvider,
    ) {
    }

    public function create(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PayUCredentials $credentials,
        string $extOrderId,
    ): array {
        $order = $payment->getOrder();
        $method = $paymentRequest->getMethod();
        $amount = $payment->getAmount();
        $currency = $payment->getCurrencyCode();

        if (!$order instanceof OrderInterface || null === $method->getCode()) {
            throw new InvalidArgumentException('Payment has no order or payment method.');
        }
        if (null === $amount || $amount <= 0 || null === $currency || '' === $currency) {
            throw new InvalidArgumentException('Payment amount and currency are required.');
        }

        $description = sprintf('Order %s', (string) $order->getNumber());

        $body = [
            'notifyUrl' => $this->urlGenerator->generate(self::NOTIFY_ROUTE, ['code' => $method->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
            'continueUrl' => $this->continueUrl($paymentRequest),
            'customerIp' => $this->customerIp($order),
            'merchantPosId' => $credentials->posId,
            'description' => $description,
            'currencyCode' => $currency,
            'totalAmount' => (string) $amount,
            'extOrderId' => $extOrderId,
            'products' => [
                [
                    'name' => $description,
                    'unitPrice' => (string) $amount,
                    'quantity' => '1',
                ],
            ],
        ];

        $buyer = $this->buyer($order);
        if ([] !== $buyer) {
            $body['buyer'] = $buyer;
        }

        return $body;
    }

    private function continueUrl(PaymentRequestInterface $paymentRequest): string
    {
        $payload = $paymentRequest->getPayload();
        $requested = is_array($payload) ? ($payload[self::PAYLOAD_CONTINUE_URL] ?? null) : null;

        if (null !== $requested) {
            if (!is_string($requested) || !self::isAbsoluteHttpUrl($requested)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must be an absolute http(s) URL.', self::PAYLOAD_CONTINUE_URL));
            }

            return $requested;
        }

        if (!$this->afterPayUrlProvider instanceof UrlProviderInterface) {
            throw new InvalidArgumentException(sprintf('Payload "%s" is required when the shop is not installed.', self::PAYLOAD_CONTINUE_URL));
        }

        return $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function customerIp(OrderInterface $order): string
    {
        $ip = $order->getCustomerIp();

        return null !== $ip && false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : self::FALLBACK_CUSTOMER_IP;
    }

    /**
     * @return array<string, string>
     */
    private function buyer(OrderInterface $order): array
    {
        $buyer = [];

        $email = $order->getCustomer()?->getEmail();
        if (null !== $email && '' !== $email) {
            $buyer['email'] = $email;
        }

        $address = $order->getBillingAddress();
        if ($address instanceof AddressInterface) {
            $buyer += array_filter([
                'firstName' => (string) $address->getFirstName(),
                'lastName' => (string) $address->getLastName(),
                'phone' => (string) $address->getPhoneNumber(),
            ], static fn (string $value): bool => '' !== $value);
        }

        if ([] !== $buyer && null !== $order->getLocaleCode()) {
            $buyer['language'] = strtolower(substr($order->getLocaleCode(), 0, 2));
        }

        return $buyer;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }
}
