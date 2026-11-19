<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private const string ERROR_RENDERER = 'error_handler.error_renderer.serializer';
    private const int ERROR_RENDERER_FORMAT_ARGUMENT = 1;

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition(self::ERROR_RENDERER)->replaceArgument(self::ERROR_RENDERER_FORMAT_ARGUMENT, JsonEncoder::FORMAT);
    }

    /**
     * @return list<string>
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
