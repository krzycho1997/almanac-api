<?php

declare(strict_types=1);

namespace App\Briefing\DependencyInjection;

use App\Briefing\Exception\SourceDefinitionException;
use App\Briefing\Source\Source;
use App\Briefing\SourceName;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final readonly class OneSourcePerNamePass implements CompilerPassInterface
{
    private const int EXACTLY_ONE = 1;
    private const string NAME = 'NAME';
    private const bool REJECT_ABSTRACT = true;

    public function process(ContainerBuilder $container): void
    {
        $servicesByName = self::servicesByName($container);

        foreach (SourceName::cases() as $name) {
            self::assertExactlyOne($name, $servicesByName[$name->value] ?? []);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function servicesByName(ContainerBuilder $container): array
    {
        $servicesByName = [];

        foreach (array_keys($container->findTaggedServiceIds(Source::class, self::REJECT_ABSTRACT)) as $serviceId) {
            $class = self::classOf($container, $serviceId);
            $servicesByName[self::nameOf($container, $serviceId, $class)->value][] = self::described($serviceId, $class);
        }

        return $servicesByName;
    }

    private static function classOf(ContainerBuilder $container, string $serviceId): string
    {
        $class = $container->getParameterBag()->resolveValue(self::declaredClass($container, $container->getDefinition($serviceId)));

        if (\is_string($class)) {
            return $class;
        }

        throw SourceDefinitionException::classNotFound($serviceId);
    }

    private static function declaredClass(ContainerBuilder $container, Definition $definition): ?string
    {
        if ($definition instanceof ChildDefinition) {
            return $definition->getClass() ?? self::declaredClass($container, $container->getDefinition($definition->getParent()));
        }

        return $definition->getClass();
    }

    private static function nameOf(ContainerBuilder $container, string $serviceId, string $class): SourceName
    {
        $reflection = $container->getReflectionClass($class, false);

        if (null === $reflection) {
            throw SourceDefinitionException::classNotFound($serviceId);
        }

        if ($reflection->implementsInterface(Source::class)) {
            return self::declaredName($reflection, $serviceId);
        }

        throw SourceDefinitionException::notASource($serviceId, $class);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private static function declaredName(\ReflectionClass $reflection, string $serviceId): SourceName
    {
        if ($reflection->hasConstant(self::NAME)) {
            return self::sourceName($reflection->getConstant(self::NAME), $reflection, $serviceId);
        }

        throw SourceDefinitionException::nameNotDeclared($serviceId, $reflection->getName());
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private static function sourceName(mixed $name, \ReflectionClass $reflection, string $serviceId): SourceName
    {
        if ($name instanceof SourceName) {
            return $name;
        }

        throw SourceDefinitionException::nameNotDeclared($serviceId, $reflection->getName());
    }

    private static function described(string $serviceId, string $class): string
    {
        if ($serviceId === $class) {
            return $class;
        }

        return "{$serviceId} ({$class})";
    }

    /**
     * @param list<string> $services
     */
    private static function assertExactlyOne(SourceName $name, array $services): void
    {
        if (self::EXACTLY_ONE === \count($services)) {
            return;
        }

        sort($services);

        throw SourceDefinitionException::notExactlyOne($name, $services);
    }
}
