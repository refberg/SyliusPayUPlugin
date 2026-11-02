<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Exception;

use RuntimeException;

/**
 * Base exception for PayU communication problems.
 *
 * Messages never contain credentials, tokens or raw request bodies,
 * so they are safe to log and to store in PaymentRequest::$responseData.
 */
class PayUException extends RuntimeException
{
}
