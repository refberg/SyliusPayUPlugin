<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Api;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Json;

final class JsonTest extends TestCase
{
    public function test_strings_are_returned_as_they_are(): void
    {
        self::assertSame('ORDER1', Json::string(['orderId' => 'ORDER1'], 'orderId'));
    }

    public function test_integers_are_returned_as_strings(): void
    {
        self::assertSame('123', Json::string(['refundId' => 123], 'refundId'));
    }

    #[TestWith([''])]
    #[TestWith([['ORDER1']])]
    #[TestWith([true])]
    #[TestWith([null])]
    public function test_empty_and_non_scalar_values_are_null(mixed $value): void
    {
        self::assertNull(Json::string(['orderId' => $value], 'orderId'));
    }

    public function test_missing_key_is_null(): void
    {
        self::assertNull(Json::string(['orderId' => 'ORDER1'], 'extOrderId'));
    }
}
