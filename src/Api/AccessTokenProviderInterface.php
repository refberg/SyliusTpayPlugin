<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;

interface AccessTokenProviderInterface
{
    /**
     * @throws TpayException
     */
    public function getToken(TpayCredentials $credentials): string;

    public function invalidate(TpayCredentials $credentials): void;
}
