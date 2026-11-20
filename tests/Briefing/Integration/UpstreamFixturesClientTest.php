<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Tests\Support\Briefing\Recording\FixtureFiles;
use App\Tests\Support\Briefing\Recording\ProbesClients;
use App\Tests\Support\Briefing\Recording\UpstreamFixture;
use App\Tests\Support\Briefing\Recording\UpstreamFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class UpstreamFixturesClientTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ProbesClients;

    private const string NOW = '2026-11-20 12:00:00 UTC';

    #[DataProvider('requestedFixtures')]
    public function testOurClientRequestsTheUrlTheFixtureWasRecordedFor(UpstreamFixture $fixture): void
    {
        // Given
        self::mockTime(self::NOW);
        $probe = self::probe();

        // When
        $probe->resolve($fixture, new FixtureFiles()->contents($fixture->path));

        // Then
        self::assertContains($fixture->call?->url, $probe->requestedUrls());
    }

    /**
     * @return iterable<string, array{UpstreamFixture}>
     */
    public static function requestedFixtures(): iterable
    {
        foreach (UpstreamFixtures::requestedByOurClients() as $fixture) {
            yield $fixture->path => [$fixture];
        }
    }
}
