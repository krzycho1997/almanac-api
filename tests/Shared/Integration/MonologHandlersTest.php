<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Tests\Support\InspectedContainer;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class MonologHandlersTest extends TestCase
{
    private const string HANDLER_PREFIX = 'monolog.handler.';
    private const string HANDLER_TAG = 'monolog.handler';
    private const string REQUEST_LOGGER = 'monolog.logger.request';
    private const string REDACTING_FORMATTER = 'app.monolog.formatter.redacting_json';
    private const string SET_FORMATTER = 'setFormatter';
    private const string PUSH_HANDLER = 'pushHandler';
    private const string LEVEL_PARAMETER = 'level';
    private const string CONSTRUCTOR = '__construct';

    #[DataProvider('environments')]
    public function testEveryWritingHandlerRedacts(string $environment): void
    {
        // Given
        $container = InspectedContainer::of($environment);

        // When
        $formatters = array_map(self::formatterOf(...), self::writingHandlers($container, self::taggedHandlerIds($container)));

        // Then
        self::assertNotEmpty($formatters);
        self::assertSame(array_fill_keys(array_keys($formatters), self::REDACTING_FORMATTER), $formatters);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
        yield 'test' => ['test'];
    }

    #[DataProvider('deployedEnvironments')]
    public function testWritesClientErrorsButNotEveryRequestFromTheRequestChannel(string $environment): void
    {
        // Given
        $container = InspectedContainer::of($environment);

        // When
        $levels = array_map(
            static fn (Definition $handler): string => self::levelOf($container, $handler),
            self::writingHandlers($container, self::handlerIdsOf($container, self::REQUEST_LOGGER)),
        );

        // Then
        self::assertSame([LogLevel::NOTICE], array_values($levels));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deployedEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }

    /**
     * @return list<string>
     */
    private static function taggedHandlerIds(ContainerBuilder $container): array
    {
        return array_values(array_filter(
            array_keys($container->findTaggedServiceIds(self::HANDLER_TAG)),
            static fn (string $id): bool => str_starts_with($id, self::HANDLER_PREFIX),
        ));
    }

    /**
     * @return list<string>
     */
    private static function handlerIdsOf(ContainerBuilder $container, string $logger): array
    {
        $handlerIds = [];
        foreach ($container->getDefinition($logger)->getMethodCalls() as [$method, $arguments]) {
            if (self::PUSH_HANDLER === $method) {
                $handlerIds[] = (string) $arguments[0];
            }
        }

        return $handlerIds;
    }

    /**
     * @param list<string> $handlerIds
     *
     * @return array<string, Definition>
     */
    private static function writingHandlers(ContainerBuilder $container, array $handlerIds): array
    {
        $handlers = [];
        foreach ($handlerIds as $id) {
            $handlers[$id] = $container->findDefinition($id);
        }

        return array_filter($handlers, static fn (Definition $handler): bool => NullHandler::class !== $handler->getClass());
    }

    private static function formatterOf(Definition $handler): ?string
    {
        foreach ($handler->getMethodCalls() as [$method, $arguments]) {
            if (self::SET_FORMATTER === $method) {
                return self::referencedId($arguments[0]);
            }
        }

        return null;
    }

    private static function referencedId(mixed $argument): ?string
    {
        if ($argument instanceof Reference) {
            return (string) $argument;
        }

        return null;
    }

    private static function levelOf(ContainerBuilder $container, Definition $handler): string
    {
        $class = $handler->getClass();
        self::assertNotNull($class);
        self::assertTrue(class_exists($class));
        $level = $container->resolveEnvPlaceholders($handler->getArgument(self::levelPosition($class)), true);
        self::assertIsString($level);

        return strtolower($level);
    }

    /**
     * @param class-string $class
     */
    private static function levelPosition(string $class): int
    {
        foreach (new \ReflectionMethod($class, self::CONSTRUCTOR)->getParameters() as $parameter) {
            if (self::LEVEL_PARAMETER === $parameter->getName()) {
                return $parameter->getPosition();
            }
        }

        self::fail("{$class} takes no log level.");
    }
}
