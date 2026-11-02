<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client used by the plugin in tests.
 *
 * By default every request goes to FakePayU. Only with PAYU_SANDBOX=1 (manual sandbox tests)
 * a real client is used, restricted to the PayU sandbox host: production is never reachable from tests.
 */
final class TestHttpClientFactory
{
    public static function create(FakePayU $fakePayU): HttpClientInterface
    {
        if (!SandboxEnvironment::isEnabled()) {
            return new MockHttpClient($fakePayU, 'https://' . FakePayU::HOST);
        }

        return new SandboxOnlyHttpClient(HttpClient::create());
    }
}
