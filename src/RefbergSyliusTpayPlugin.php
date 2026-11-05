<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin;

use Refberg\SyliusTpayPlugin\DependencyInjection\RefbergSyliusTpayExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusTpayPlugin extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new RefbergSyliusTpayExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
