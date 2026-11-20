<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Source\Source;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class BriefingAggregator
{
    /**
     * @var list<Source>
     */
    private array $sources;

    /**
     * @param iterable<Source> $sources
     */
    public function __construct(
        #[AutowireIterator(Source::class)]
        iterable $sources,
        private ClockInterface $clock,
        private SourceFailureLog $failureLog,
    ) {
        $this->sources = array_values([...$sources]);
    }

    public function briefingFor(\DateTimeImmutable $date, Location $location): BriefingResult
    {
        $fetches = array_map(fn (Source $source): \Closure => $this->start($source, $date, $location), $this->sources);
        $results = array_map(static fn (\Closure $settle): SourceResult => $settle(), $fetches);
        $this->recordFailures($results, $date);

        return new BriefingResult($date, $location, $results, $this->clock->now());
    }

    /**
     * @param list<SourceResult> $results
     */
    private function recordFailures(array $results, \DateTimeImmutable $date): void
    {
        foreach ($results as $result) {
            $this->failureLog->record($result, $date);
        }
    }

    /**
     * @return \Closure(): SourceResult
     */
    private function start(Source $source, \DateTimeImmutable $date, Location $location): \Closure
    {
        $name = $source->name();
        $client = $source->client();

        if ($client->covers($date)) {
            $pending = $client->request($date, $location);

            return static fn (): SourceResult => $pending->resolve($name);
        }

        return static fn (): SourceResult => SourceResult::notApplicable($name);
    }
}
