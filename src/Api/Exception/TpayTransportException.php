<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Exception;

/**
 * Network-level failure (timeout, DNS, TLS) or an unreadable response. Always safe to retry.
 */
final class TpayTransportException extends TpayException
{
}
