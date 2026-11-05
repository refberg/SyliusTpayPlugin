<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Exception;

use function in_array;

/**
 * Tpay answered, but with an error (4xx/5xx or result "failed").
 */
final class TpayApiException extends TpayException
{
    public function __construct(
        string $message,
        private readonly int $httpStatusCode,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isTransient(): bool
    {
        return $this->httpStatusCode >= 500 || in_array($this->httpStatusCode, [408, 429], true);
    }

    /**
     * 401/403: the merchant has to fix the credentials or the API permissions.
     */
    public function isAuthorizationError(): bool
    {
        return in_array($this->httpStatusCode, [401, 403], true);
    }
}
