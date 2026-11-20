<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class ExposeServicesPass implements CompilerPassInterface
{
    /**
     * @param list<string> $serviceIds
     */
    public function __construct(
        private array $serviceIds,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->serviceIds as $id) {
            self::expose($container, $id);
        }
    }

    private static function expose(ContainerBuilder $container, string $id): void
    {
        if ($container->hasAlias($id)) {
            $container->getAlias($id)->setPublic(true);

            return;
        }

        $container->getDefinition($id)->setPublic(true);
    }
}
