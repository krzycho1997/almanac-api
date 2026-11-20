<?php

declare(strict_types=1);

namespace App;

use App\Briefing\DependencyInjection\OneSourcePerNamePass;
use App\Shared\ErrorHandler\ClientErrorLevelErrorListener;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private const string ERROR_RENDERER = 'error_handler.error_renderer.serializer';
    private const string EXCEPTION_LISTENER = 'exception_listener';
    private const int ERROR_RENDERER_FORMAT_ARGUMENT = 1;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new OneSourcePerNamePass());
    }

    public function process(ContainerBuilder $container): void
    {
        self::renderErrorsAsJson($container);
        self::logClientErrorsAtNotice($container);
    }

    private static function renderErrorsAsJson(ContainerBuilder $container): void
    {
        $container->getDefinition(self::ERROR_RENDERER)->replaceArgument(self::ERROR_RENDERER_FORMAT_ARGUMENT, JsonEncoder::FORMAT);
    }

    private static function logClientErrorsAtNotice(ContainerBuilder $container): void
    {
        $container->getDefinition(self::EXCEPTION_LISTENER)->setClass(ClientErrorLevelErrorListener::class);
    }

    /**
     * @return list<string>
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
