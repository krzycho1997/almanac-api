<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Tests\Support\Briefing\Recording\FixtureFiles;
use App\Tests\Support\Briefing\Recording\UpstreamFixture;
use App\Tests\Support\Briefing\Recording\UpstreamFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpstreamFixturesManifestTest extends TestCase
{
    private const string NASA_API = 'api.nasa.gov';
    private const string WIKIPEDIA = 'en.wikipedia.org';
    private const array LEAKS = ['api_key', 'DEMO_KEY', 'set-cookie', 'x-client-ip', 'GeoIP'];
    private const array KEPT_HEADERS = ['content-type', 'date', 'retry-after', 'x-ratelimit-limit', 'x-ratelimit-window'];

    public function testListsEveryFixtureFileExactlyOnce(): void
    {
        // When
        $listed = array_map(static fn (UpstreamFixture $fixture): string => $fixture->path, UpstreamFixtures::all());

        // Then
        self::assertSame(array_values(array_unique($listed)), $listed);
        self::assertEqualsCanonicalizing(new FixtureFiles()->bodies(), $listed);
    }

    public function testRecordsWhereEveryFixtureCameFrom(): void
    {
        // Given
        $files = new FixtureFiles();

        // When
        $recorded = [];

        foreach ($files->directories() as $directory) {
            foreach (array_keys(get_object_vars($files->recorded($directory))) as $file) {
                $recorded[] = $directory.'/'.$file;
            }
        }

        // Then
        self::assertEqualsCanonicalizing($files->bodies(), $recorded);
    }

    #[DataProvider('fixtures')]
    public function testStoresTheFixtureInTheRecordersFormat(UpstreamFixture $fixture): void
    {
        // When
        $contents = new FixtureFiles()->contents($fixture->path);

        // Then
        self::assertSame(FixtureFiles::encode(json_decode($contents, false, flags: \JSON_THROW_ON_ERROR)), $contents);
    }

    #[DataProvider('fixtures')]
    public function testRecordsTheUrlAndStatusTheManifestExpects(UpstreamFixture $fixture): void
    {
        // When
        $recorded = new FixtureFiles()->recorded(\dirname($fixture->path))->{basename($fixture->path)} ?? null;

        // Then
        self::assertInstanceOf(\stdClass::class, $recorded);
        self::assertSame([$fixture->url, $fixture->expectedStatus], [$recorded->url ?? null, $recorded->status ?? null]);
    }

    #[DataProvider('fixtures')]
    public function testKeepsOnlyTheAllowedHeaders(UpstreamFixture $fixture): void
    {
        // When
        $headers = new FixtureFiles()->recorded(\dirname($fixture->path))->{basename($fixture->path)}->headers ?? null;

        // Then
        self::assertInstanceOf(\stdClass::class, $headers);
        self::assertSame([], array_diff(array_keys(get_object_vars($headers)), self::KEPT_HEADERS));
    }

    public function testComparesOnlyTheEditedWikipediaListsByShape(): void
    {
        // When
        $byShape = array_filter(UpstreamFixtures::all(), static fn (UpstreamFixture $fixture): bool => false === $fixture->isComparedExactly());

        // Then
        self::assertSame([self::WIKIPEDIA], array_values(array_unique(array_map(static fn (UpstreamFixture $fixture): string => $fixture->host(), $byShape))));
    }

    public function testRecordsRepeatableFixturesWithoutTheNasaKey(): void
    {
        // When
        $hosts = array_map(static fn (UpstreamFixture $fixture): string => $fixture->host(), UpstreamFixtures::repeatable());

        // Then
        self::assertNotContains(self::NASA_API, $hosts);
    }

    public function testKeepsKeysCookiesAndAddressesOutOfTheFixtures(): void
    {
        // Given
        $files = new FixtureFiles();
        $paths = [...$files->bodies(), ...array_map(static fn (string $directory): string => $directory.'/'.FixtureFiles::RECORDED, $files->directories())];

        // When
        $leaks = [];

        foreach ($paths as $path) {
            foreach (self::LEAKS as $leak) {
                if (str_contains($files->contents($path), $leak)) {
                    $leaks[] = "{$path}: {$leak}";
                }
            }
        }

        // Then
        self::assertSame([], $leaks);
    }

    /**
     * @return iterable<string, array{UpstreamFixture}>
     */
    public static function fixtures(): iterable
    {
        foreach (UpstreamFixtures::all() as $fixture) {
            yield $fixture->path => [$fixture];
        }
    }
}
