<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use DateTime;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;

/**
 * Runs the real Sylius test application with the PayU HTTP client replaced by FakePayU.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    protected const METHOD_CODE = 'PAYU';

    protected KernelBrowser $client;

    protected FakePayU $payu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        // Keep one kernel (and one FakePayU state) for all requests of a test.
        $this->client->disableReboot();

        $this->payu = self::service(FakePayU::class, FakePayU::class);

        $connection = $this->em()->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        (new ORMPurger($this->em()))->purge();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function em(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    /**
     * Creates a placed order (checkout completed) with one PayU payment of 15.00 PLN awaiting payment.
     */
    protected function createPlacedOrder(int $amount = 1500): PaymentInterface
    {
        $locale = $this->factory('locale')->createNew();
        self::assertInstanceOf(LocaleInterface::class, $locale);
        $locale->setCode('en_US');

        $currency = $this->factory('currency')->createNew();
        self::assertInstanceOf(CurrencyInterface::class, $currency);
        $currency->setCode('PLN');

        $channel = $this->factory('channel')->createNew();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setTaxCalculationStrategy('order_items_based');

        $gatewayConfig = $this->factory('gateway_config')->createNew();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName(PayUGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName('payu');
        $gatewayConfig->setConfig(['environment' => 'sandbox'] + $this->payuCredentials());
        if ($gatewayConfig instanceof GatewayConfig) {
            $gatewayConfig->setUsePayum(false);
        }

        $method = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $method->setCode(self::METHOD_CODE);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('PayU');
        $method->setEnabled(true);
        $method->setGatewayConfig($gatewayConfig);
        $method->addChannel($channel);

        $customer = $this->factory('customer')->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail('buyer@example.com');

        $order = $this->factory('order')->createNew();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setCurrencyCode('PLN');
        $order->setLocaleCode('en_US');
        $order->setCustomer($customer);
        $order->setNumber('000000001');
        $order->setTokenValue('order-token');
        $order->setCheckoutState('completed');
        $order->setState('new');
        $order->setPaymentState('awaiting_payment');
        $order->setCustomerIp('10.0.0.1');
        $order->setCheckoutCompletedAt(new DateTime());

        $payment = $this->factory('payment')->createNew();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($method);
        $payment->setAmount($amount);
        $payment->setCurrencyCode('PLN');
        $payment->setState('new');
        $order->addPayment($payment);

        $em = $this->em();
        foreach ([$locale, $currency, $channel, $gatewayConfig, $method, $customer, $order, $payment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $payment;
    }

    /**
     * @return array<string, string>
     */
    protected function payuCredentials(): array
    {
        return [
            'pos_id' => FakePayU::POS_ID,
            'second_key' => FakePayU::SECOND_KEY,
            'oauth_client_id' => FakePayU::CLIENT_ID,
            'oauth_client_secret' => FakePayU::CLIENT_SECRET,
        ];
    }

    /**
     * Goes through the shop "pay" flow and returns the URL PayU would redirect the customer to.
     */
    protected function payInShop(): string
    {
        $this->client->request('GET', '/en_US/order/order-token/pay');
        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect(), (string) $response->getContent());

        $this->client->request('GET', (string) $response->headers->get('Location'));
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->headers->get('Location');
    }

    protected function notify(string $body, ?string $signatureHeader): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signatureHeader) {
            $server['HTTP_OPENPAYU_SIGNATURE'] = $signatureHeader;
        }

        $this->client->request('POST', '/payment-methods/' . self::METHOD_CODE, server: $server, content: $body);

        return $this->client->getResponse();
    }

    protected function notifyOrder(string $orderId, ?string $reportedStatus = null): Response
    {
        $body = $this->payu->orderNotificationBody($orderId, $reportedStatus);

        return $this->notify($body, FakePayU::signatureHeader($body));
    }

    protected function reloadPayment(PaymentInterface $payment): PaymentInterface
    {
        $this->em()->clear();
        $reloaded = $this->em()->find($payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $reloaded);

        return $reloaded;
    }

    protected function details(PaymentInterface $payment): PaymentDetails
    {
        return PaymentDetails::of($this->reloadPayment($payment));
    }

    /**
     * @return list<PaymentRequestInterface>
     */
    protected function paymentRequests(string $action): array
    {
        $this->em()->clear();
        $repository = self::service(ObjectRepository::class, 'sylius.repository.payment_request');

        /** @var list<PaymentRequestInterface> $requests */
        $requests = $repository->findBy(['action' => $action]);

        return $requests;
    }

    /**
     * Reads a nested value from decoded JSON, failing the test when the path does not exist.
     *
     * @param array<array-key, mixed> $data
     */
    protected static function at(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return FactoryInterface<object>
     */
    private function factory(string $resource): FactoryInterface
    {
        return self::service(FactoryInterface::class, 'sylius.factory.' . $resource);
    }

    /**
     * Fetches a service from the test container and checks its type.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class, string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
