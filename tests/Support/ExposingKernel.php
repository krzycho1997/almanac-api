<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ExposingKernel extends Kernel
{
    private const string CACHE_DIR = '/almanac-exposing-kernel/';
    private const string SEPARATOR = ',';

    /**
     * @param list<string> $serviceIds
     */
    private function __construct(string $environment, private readonly array $serviceIds)
    {
        parent::__construct($environment, true);
    }

    /**
     * @param list<string> $serviceIds
     */
    public static function booted(string $environment, array $serviceIds): ContainerInterface
    {
        $kernel = new self($environment, $serviceIds);
        $kernel->boot();

        return $kernel->getContainer();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().self::CACHE_DIR.$this->environment.'/'.md5(implode(self::SEPARATOR, $this->serviceIds));
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ExposeServicesPass($this->serviceIds), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
