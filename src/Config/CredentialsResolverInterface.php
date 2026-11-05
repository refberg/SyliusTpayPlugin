<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Config;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface CredentialsResolverInterface
{
    public function supports(PaymentMethodInterface $paymentMethod): bool;

    /**
     * @throws InvalidConfigurationException
     */
    public function resolve(PaymentMethodInterface $paymentMethod): TpayCredentials;
}
