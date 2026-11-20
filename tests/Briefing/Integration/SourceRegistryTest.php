<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingAggregator;
use App\Briefing\DependencyInjection\OneSourcePerNamePass;
use App\Briefing\Location;
use App\Briefing\Source\Source;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InspectedContainer;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class SourceRegistryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string BEFORE_EVERY_DATED_SOURCE = '1900-03-15';

    #[DataProvider('environments')]
    public function testChecksTheSourcesWheneverTheContainerIsBuilt(string $environment): void
    {
        // When
        $passes = InspectedContainer::of($environment)->getCompilerPassConfig()->getPasses();

        // Then
        self::assertCount(1, array_filter($passes, static fn (object $pass): bool => $pass instanceof OneSourcePerNamePass));
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

    public function testBuildsABriefingWithOneResultForEverySourceName(): void
    {
        // Given
        self::mockTime(self::NOW);
        self::testService(UpstreamMockResponseFactory::class)->respondWith(UpstreamMock::create()->onThisDayFrom('03-15'));

        // When
        $briefing = self::testService(BriefingAggregator::class)->briefingFor(new \DateTimeImmutable(self::BEFORE_EVERY_DATED_SOURCE), Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame(SourceName::cases(), array_map(static fn (SourceName $name): SourceName => $briefing->resultFor($name)->source, SourceName::cases()));
        self::assertSame([SourceName::Apod, SourceName::Weather], $briefing->missing());
    }

    /**
     * @param class-string<Source> $class
     */
    #[DataProvider('sourceClasses')]
    public function testEverySourceAnswersWithTheNameItDeclares(string $class): void
    {
        // When
        $source = self::testService($class);

        // Then
        self::assertSame(\constant($class.'::NAME'), $source->name());
    }

    /**
     * @return iterable<string, array{class-string<Source>}>
     */
    public static function sourceClasses(): iterable
    {
        foreach (array_keys(InspectedContainer::of('test')->findTaggedServiceIds(Source::class)) as $serviceId) {
            if (is_subclass_of($serviceId, Source::class)) {
                yield $serviceId => [$serviceId];
            }
        }
    }
}
