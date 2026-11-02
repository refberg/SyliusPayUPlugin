<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUAuthenticationException;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUTransportException;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Obtains OAuth access tokens (grant_type=client_credentials) and caches them until shortly before expiry.
 */
final class AccessTokenProvider implements AccessTokenProviderInterface
{
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly float $timeout = 15.0,
    ) {
    }

    public function getToken(PayUCredentials $credentials): string
    {
        /** @var string $token */
        $token = $this->cache->get(
            $this->cacheKey($credentials),
            fn (ItemInterface $item): string => $this->fetchToken($credentials, $item),
        );

        return $token;
    }

    public function invalidate(PayUCredentials $credentials): void
    {
        $this->cache->delete($this->cacheKey($credentials));
    }

    private function fetchToken(PayUCredentials $credentials, ItemInterface $item): string
    {
        try {
            $response = $this->httpClient->request('POST', $credentials->environment->baseUrl() . '/pl/standard/user/oauth/authorize', [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $credentials->clientId,
                    'client_secret' => $credentials->clientSecret,
                ],
                'max_redirects' => 0,
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $data = 200 === $statusCode ? $response->toArray(false) : [];
        } catch (TransportExceptionInterface) {
            throw new PayUTransportException('Could not reach PayU OAuth endpoint.');
        } catch (HttpClientExceptionInterface) {
            throw new PayUAuthenticationException('PayU OAuth endpoint returned an unreadable response.');
        }

        if (200 !== $statusCode) {
            throw new PayUAuthenticationException(sprintf('PayU OAuth authorization failed (HTTP %d). Check the OAuth client ID and secret.', $statusCode));
        }

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || '' === $token) {
            throw new PayUAuthenticationException('PayU OAuth response did not contain an access token.');
        }

        $expiresIn = $data['expires_in'] ?? 0;
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 0;
        $item->expiresAfter(max(1, $expiresIn - self::EXPIRY_MARGIN_SECONDS));

        return $token;
    }

    private function cacheKey(PayUCredentials $credentials): string
    {
        // The secret is part of the key (hashed), so rotating it invalidates the cached token.
        return 'refberg_payu_token_' . hash('sha256', implode("\0", [
            $credentials->environment->value,
            $credentials->clientId,
            $credentials->clientSecret,
        ]));
    }
}
