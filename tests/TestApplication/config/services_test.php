<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\TestHttpClientFactory;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    if (!str_starts_with((string) $container->env(), 'test')) {
        return;
    }

    $services = $container->services();

    // Tpay traffic in tests goes to an in-memory fake of the Tpay sandbox; FakeTpay rejects any
    // other host. Only TPAY_SANDBOX=1 switches to the real sandbox (see tests/Sandbox).
    $services->set(FakeTpay::class)->public();

    $services->set('refberg_tpay.http_client', HttpClientInterface::class)
        ->factory([TestHttpClientFactory::class, 'create'])
        ->args([service(FakeTpay::class)])
        ->public();
};
