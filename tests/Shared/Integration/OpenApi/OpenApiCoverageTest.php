<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\OpenApi;

use App\Shared\Controller\MetricsController;
use App\Tests\Support\OpenApi\OpenApiDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class OpenApiCoverageTest extends KernelTestCase
{
    private const string CONTROLLER = '_controller';
    private const string APPLICATION = 'App\\';
    private const string TEST_SUPPORT = 'App\\Tests\\';
    private const array OUTSIDE_THE_PUBLIC_CONTRACT = [MetricsController::PATH];

    public function testDocumentsExactlyTheOperationsTheApplicationRoutes(): void
    {
        // Given
        $routed = self::routedOperations();

        // When
        $documented = self::documentedOperations();

        // Then
        self::assertNotEmpty($routed);
        self::assertSame($routed, $documented);
    }

    public function testRoutesEveryOperationOnlyForExplicitMethods(): void
    {
        // When
        $routesWithoutMethods = array_keys(array_filter(self::applicationRoutes(), static fn (Route $route): bool => [] === $route->getMethods()));

        // Then
        self::assertSame([], $routesWithoutMethods);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function routedOperations(): array
    {
        $operations = [];
        foreach (self::publicRoutes() as $route) {
            $operations[$route->getPath()] = [...$operations[$route->getPath()] ?? [], ...array_values(array_map(strtolower(...), $route->getMethods()))];
        }

        return self::sorted($operations);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function documentedOperations(): array
    {
        return self::sorted(OpenApiDocument::instance()->operations());
    }

    /**
     * @return array<string, Route>
     */
    private static function publicRoutes(): array
    {
        return array_filter(self::applicationRoutes(), static fn (Route $route): bool => self::isInThePublicContract($route->getPath()));
    }

    private static function isInThePublicContract(string $path): bool
    {
        if (\in_array($path, self::OUTSIDE_THE_PUBLIC_CONTRACT, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, Route>
     */
    private static function applicationRoutes(): array
    {
        return array_filter(self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all(), self::isServedByTheApplication(...));
    }

    private static function isServedByTheApplication(Route $route): bool
    {
        $controller = $route->getDefault(self::CONTROLLER);

        if (\is_string($controller)) {
            return self::isApplicationController($controller);
        }

        return false;
    }

    private static function isApplicationController(string $controller): bool
    {
        if (str_starts_with($controller, self::TEST_SUPPORT)) {
            return false;
        }

        return str_starts_with($controller, self::APPLICATION);
    }

    /**
     * @param array<string, list<string>> $operations
     *
     * @return array<string, list<string>>
     */
    private static function sorted(array $operations): array
    {
        ksort($operations);

        return array_map(static function (array $methods): array {
            sort($methods);

            return $methods;
        }, $operations);
    }
}
