<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Exception;

/**
 * Network-level failure (timeout, DNS, TLS). Always safe to retry.
 */
final class PayUTransportException extends PayUException
{
}
