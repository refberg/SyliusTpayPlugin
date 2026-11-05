<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\DependencyInjection\RefbergSyliusTpayExtension;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusTpayPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\ShopBundle\SyliusShopBundle;
use Sylius\TwigHooks\DependencyInjection\SyliusTwigHooksExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use function interface_exists;
use function str_starts_with;

final class RefbergSyliusTpayExtensionTest extends TestCase
{
    public function test_shop_services_are_registered_with_the_shop_bundle(): void
    {
        $container = self::load(['SyliusShopBundle' => SyliusShopBundle::class]);

        self::assertTrue($container->hasDefinition(StatusHttpResponseProvider::class));
        $afterPayUrlProvider = $container->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(1);
        self::assertInstanceOf(Reference::class, $afterPayUrlProvider);
        self::assertSame('sylius_shop.provider.order_pay.after_pay_url', (string) $afterPayUrlProvider);
    }

    public function test_shop_services_are_not_registered_without_the_shop_bundle(): void
    {
        $container = self::load([]);

        self::assertFalse($container->hasDefinition(StatusHttpResponseProvider::class));
        self::assertNull($container->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(1));
    }

    public function test_every_injected_plugin_service_is_registered_under_its_interface(): void
    {
        $container = self::load(['SyliusShopBundle' => SyliusShopBundle::class]);

        foreach ($container->getDefinitions() as $id => $definition) {
            foreach ($definition->getArguments() as $argument) {
                if (!$argument instanceof Reference || !str_starts_with((string) $argument, 'Refberg\\SyliusTpayPlugin\\')) {
                    continue;
                }

                self::assertTrue(interface_exists((string) $argument), $id . ' gets ' . $argument);
                self::assertStringEndsWith('Interface', (string) $argument, (string) $argument);
            }
        }
    }

    public function test_admin_twig_hooks_are_prepended_when_twig_hooks_are_enabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SyliusTwigHooksExtension());

        (new RefbergSyliusTpayExtension())->prepend($container);

        $gatewayConfiguration = [
            'refberg_tpay' => [
                'template' => '@RefbergSyliusTpayPlugin/admin/payment_method/gateway_configuration.html.twig',
                'priority' => 0,
            ],
        ];
        self::assertSame([['hooks' => [
            'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.refberg_tpay' => $gatewayConfiguration,
            'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.refberg_tpay' => $gatewayConfiguration,
        ]]], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function test_no_twig_hooks_are_configured_without_twig_hooks(): void
    {
        $container = new ContainerBuilder();

        (new RefbergSyliusTpayExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function test_http_timeout_is_a_container_parameter(): void
    {
        self::assertEquals(15.0, self::load([])->getParameter('refberg_tpay.http_timeout'));
    }

    /**
     * @param array<string, class-string> $bundles
     */
    private static function load(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);

        (new RefbergSyliusTpayExtension())->load([], $container);

        return $container;
    }
}
