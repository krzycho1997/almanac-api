<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProviderClientsConfigTest extends TestCase
{
    private const string CONFIG = __DIR__.'/../../../../config/';
    private const string FRAMEWORK = 'packages/framework.yaml';
    private const string SERVICES = 'services.yaml';
    private const array YAML_FILES = ['*.yaml', 'packages/*.yaml', 'routes/*.yaml'];
    private const string UPSTREAM_STREAM = 'app.briefing.upstream_stream';
    private const string BASE_CLIENT = 'http_client';
    private const string TEST_SECTION = 'when@test';
    private const int FLAGS = Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS;

    public function testDeclaresEveryProviderClientInTheFrameworkConfig(): void
    {
        // When
        $clients = self::providerClients();

        // Then
        self::assertSame(['nasa.client', 'open_meteo.client', 'open_meteo_archive.client', 'wikipedia.client'], $clients);
    }

    public function testStreamsEveryUpstreamThroughTheBaseClient(): void
    {
        // Given
        $services = self::configIn(self::CONFIG.self::SERVICES)['services'] ?? null;
        self::assertIsArray($services);

        // When
        $stream = $services[self::UPSTREAM_STREAM] ?? null;

        // Then
        self::assertSame(['alias' => self::BASE_CLIENT], $stream);
    }

    public function testWiresNoProviderClientOutsideTheFrameworkConfig(): void
    {
        // Given
        $wiring = implode("\n", array_map(self::wiringIn(...), self::otherConfigFiles()));

        // When
        $named = array_values(array_filter(self::providerClients(), static fn (string $client): bool => str_contains($wiring, $client)));

        // Then
        self::assertSame([], $named);
    }

    /**
     * @return list<string>
     */
    private static function providerClients(): array
    {
        $framework = self::configIn(self::CONFIG.self::FRAMEWORK)['framework'] ?? null;
        self::assertIsArray($framework);
        self::assertIsArray($framework['http_client'] ?? null);
        self::assertIsArray($framework['http_client']['scoped_clients'] ?? null);

        $clients = array_map(strval(...), array_keys($framework['http_client']['scoped_clients']));
        sort($clients);

        return $clients;
    }

    /**
     * @return list<string>
     */
    private static function otherConfigFiles(): array
    {
        $files = array_merge(...array_map(self::filesMatching(...), self::YAML_FILES));

        return array_values(array_diff($files, [self::CONFIG.self::FRAMEWORK]));
    }

    /**
     * @return list<string>
     */
    private static function filesMatching(string $pattern): array
    {
        $files = glob(self::CONFIG.$pattern);
        self::assertIsArray($files);

        return $files;
    }

    private static function wiringIn(string $file): string
    {
        $config = self::configIn($file);
        unset($config[self::TEST_SECTION]);

        return Yaml::dump($config, 10);
    }

    /**
     * @return array<mixed>
     */
    private static function configIn(string $file): array
    {
        $config = Yaml::parseFile($file, self::FLAGS) ?? [];
        self::assertIsArray($config);

        return $config;
    }
}
