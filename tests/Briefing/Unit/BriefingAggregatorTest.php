<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\BriefingAggregator;
use App\Briefing\Location;
use App\Briefing\SourceFailureLog;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\CallJournal;
use App\Tests\Support\Briefing\JournalingSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class BriefingAggregatorTest extends TestCase
{
    public function testStartsEveryRequestBeforeSettlingAny(): void
    {
        // Given
        $journal = new CallJournal();
        $clock = new MockClock('2026-11-20 12:00:00');
        $sources = [new JournalingSource(SourceName::Apod, $journal, $clock), new JournalingSource(SourceName::Weather, $journal, $clock)];
        $aggregator = new BriefingAggregator($sources, $clock, new SourceFailureLog(new NullLogger()));

        // When
        $aggregator->briefingFor(new \DateTimeImmutable('2026-11-20'), Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame(['request apod', 'request weather', 'settle apod', 'settle weather'], $journal->entries());
    }
}
