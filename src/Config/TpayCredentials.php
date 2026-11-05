<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Config;

use LogicException;
use SensitiveParameter;

final readonly class TpayCredentials
{
    public function __construct(
        public string $merchantId,
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        #[SensitiveParameter]
        public string $securityCode,
        public Environment $environment,
    ) {
    }

    /**
     * Keeps secrets out of var_dump(), dump() and debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'merchantId' => $this->merchantId,
            'clientId' => $this->clientId,
            'clientSecret' => '***',
            'securityCode' => '***',
            'environment' => $this->environment->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('Tpay credentials must not be serialized.');
    }
}
