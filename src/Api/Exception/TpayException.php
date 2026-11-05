<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Exception;

use RuntimeException;

/**
 * Base exception for Tpay communication problems.
 *
 * Messages never contain credentials, tokens or raw request bodies,
 * so they are safe to log and to store in PaymentRequest::$responseData.
 */
class TpayException extends RuntimeException
{
}
