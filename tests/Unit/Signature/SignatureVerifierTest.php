<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Signature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifier;
use function hash;
use function md5;
use function sprintf;
use function str_replace;
use function strtoupper;
use function substr;

final class SignatureVerifierTest extends TestCase
{
    private const KEY = 'unit-test-second-key-0123456789';

    private const BODY = '{"order":{"orderId":"LDLW5N7MF4140324GUEST000P01","extOrderId":"000001-1-abcdef012345","status":"COMPLETED"}}';

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SignatureVerifier();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function algorithms(): iterable
    {
        yield 'MD5' => ['MD5', 'md5'];
        yield 'SHA-256' => ['SHA-256', 'sha256'];
        yield 'SHA256' => ['SHA256', 'sha256'];
        yield 'SHA' => ['SHA', 'sha1'];
        yield 'SHA-384' => ['SHA-384', 'sha384'];
        yield 'SHA-512' => ['SHA-512', 'sha512'];
        yield 'lowercase sha-256' => ['sha-256', 'sha256'];
    }

    #[DataProvider('algorithms')]
    public function test_it_accepts_a_valid_signature(string $headerAlgorithm, string $phpAlgorithm): void
    {
        $header = sprintf('sender=checkout;signature=%s;algorithm=%s;content=DOCUMENT', hash($phpAlgorithm, self::BODY . self::KEY), $headerAlgorithm);

        self::assertTrue($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    public function test_it_accepts_uppercase_hex_and_spaces(): void
    {
        $header = sprintf(' sender=checkout; signature=%s ; algorithm=MD5 ;content=DOCUMENT', strtoupper(md5(self::BODY . self::KEY)));

        self::assertTrue($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    public function test_it_rejects_a_signature_made_with_another_key(): void
    {
        $header = sprintf('sender=checkout;signature=%s;algorithm=MD5;content=DOCUMENT', md5(self::BODY . 'other-key'));

        self::assertFalse($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    public function test_it_rejects_a_modified_body(): void
    {
        $header = sprintf('sender=checkout;signature=%s;algorithm=MD5;content=DOCUMENT', md5(self::BODY . self::KEY));

        self::assertFalse($this->verifier->verify(str_replace('COMPLETED', 'CANCELED', self::BODY), $header, self::KEY));
    }

    public function test_it_rejects_key_prepended_instead_of_appended(): void
    {
        $header = sprintf('sender=checkout;signature=%s;algorithm=MD5;content=DOCUMENT', md5(self::KEY . self::BODY));

        self::assertFalse($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidHeaders(): iterable
    {
        $md5 = md5(self::BODY . self::KEY);

        yield 'missing header' => [null];
        yield 'empty header' => [''];
        yield 'no signature' => ['sender=checkout;algorithm=MD5'];
        yield 'no algorithm' => ['sender=checkout;signature=' . $md5];
        yield 'unknown algorithm' => ['signature=' . $md5 . ';algorithm=CRC32'];
        yield 'not hex' => ['signature=zz' . substr($md5, 2) . ';algorithm=MD5'];
        yield 'malformed part' => ['signature=' . $md5 . ';algorithm=MD5;garbage'];
        yield 'empty signature' => ['signature=;algorithm=MD5'];
    }

    #[DataProvider('invalidHeaders')]
    public function test_it_rejects_invalid_headers(?string $header): void
    {
        self::assertFalse($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    public function test_it_rejects_empty_body_or_key(): void
    {
        $header = sprintf('signature=%s;algorithm=MD5', md5(self::KEY));

        self::assertFalse($this->verifier->verify('', $header, self::KEY));
        self::assertFalse($this->verifier->verify(self::BODY, sprintf('signature=%s;algorithm=MD5', md5(self::BODY)), ''));
    }

    public function test_header_parsing_tolerates_spacing_case_and_empty_parts(): void
    {
        $header = sprintf(' Sender = checkout ;; ; SIGNATURE = %s ; Algorithm = md5 ; content=DOC=UMENT', md5(self::BODY . self::KEY));

        self::assertTrue($this->verifier->verify(self::BODY, $header, self::KEY));
    }

    public function test_it_finds_the_signature_among_other_headers(): void
    {
        $headers = [
            'content-type' => ['application/json'],
            'authorization' => ['Basic xyz'],
            'openpayu-signature' => ['sender=checkout'],
        ];

        self::assertSame('sender=checkout', $this->verifier->extractHeader($headers));
    }

    public function test_it_extracts_the_header_case_insensitively(): void
    {
        self::assertSame('a=b', $this->verifier->extractHeader(['openpayu-signature' => ['a=b']]));
        self::assertSame('c=d', $this->verifier->extractHeader(['OpenPayU-Signature' => ['c=d']]));
        self::assertSame('e=f', $this->verifier->extractHeader(['X-OpenPayU-Signature' => 'e=f']));
        self::assertNull($this->verifier->extractHeader(['content-type' => ['application/json']]));
    }
}
