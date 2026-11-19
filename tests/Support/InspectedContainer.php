<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class InspectedContainer extends Kernel
{
    private const string CACHE_DIR = '/almanac-inspected-container/';

    public static function of(string $environment): ContainerBuilder
    {
        return new self($environment, false)->compiledBuilder();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().self::CACHE_DIR.$this->environment;
    }

    private function compiledBuilder(): ContainerBuilder
    {
        $this->initializeBundles();
        $container = $this->buildContainer();
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        return $container;
    }
}
