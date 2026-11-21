<?php

declare(strict_types=1);

namespace App\Briefing\Command;

use App\Briefing\BriefingDay;
use App\Briefing\BriefingProblem;
use App\Briefing\BriefingResult;
use App\Briefing\DateRange;
use App\Briefing\Dto\Input\PrefetchInput;
use App\Briefing\Location;
use App\Briefing\Prefetcher;
use App\Briefing\ProblemTiming;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsCommand(name: 'almanac:prefetch', description: 'Fetch and store the briefings of a range of dates')]
final readonly class PrefetchCommand
{
    private const array PROBLEM_HEADERS = ['Date', 'Source', 'Error', 'HTTP status', 'Attempt'];
    private const string NO_STATUS = '-';
    private const string FAILED_NOW = 'now';
    private const string IN_PAUSE = 'earlier, in pause';
    private const string QUEUED = 'Queued dates: %d. The worker fetches them; their sources show in the briefings, and messages that keep failing in bin/console messenger:failed:show.';
    private const string SKIPPED = 'skipped, circuit open';

    public function __construct(
        private Prefetcher $prefetcher,
        private ClockInterface $clock,
        #[Target('defaultLocation')]
        private Location $defaultLocation,
    ) {
    }

    public function __invoke(#[MapInput] PrefetchInput $input, SymfonyStyle $io): int
    {
        $range = $input->toRange($this->clock->now()->getTimezone());

        if ($input->async) {
            return $this->enqueue($input, $range, $io);
        }

        $problems = self::problemRowsOf($this->briefingsOf($input, $range, $io));

        return self::report($problems, \count($range), $io);
    }

    private function enqueue(PrefetchInput $input, DateRange $range, SymfonyStyle $io): int
    {
        $queued = $this->prefetcher->enqueue($range, $input->locationOr($this->defaultLocation), $input->delayMs());
        $io->success(\sprintf(self::QUEUED, $queued));

        return Command::SUCCESS;
    }

    /**
     * @return iterable<BriefingResult>
     */
    private function briefingsOf(PrefetchInput $input, DateRange $range, SymfonyStyle $io): iterable
    {
        $briefings = $this->prefetcher->prefetch($range, $input->locationOr($this->defaultLocation), $input->delayMs());

        return $io->progressIterate($briefings, \count($range));
    }

    /**
     * @param iterable<BriefingResult> $briefings
     *
     * @return list<list<string>>
     */
    private static function problemRowsOf(iterable $briefings): array
    {
        $rows = [];

        foreach ($briefings as $briefing) {
            foreach ($briefing->problems() as $problem) {
                $rows[] = self::rowOf($briefing->date, $problem);
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function rowOf(\DateTimeImmutable $date, BriefingProblem $problem): array
    {
        return [
            $date->format(BriefingDay::FORMAT),
            $problem->source->value,
            $problem->failure->kind->value,
            (string) ($problem->failure->httpStatus ?? self::NO_STATUS),
            self::attemptOf($problem->timing),
        ];
    }

    private static function attemptOf(ProblemTiming $timing): string
    {
        return match ($timing) {
            ProblemTiming::FailedNow => self::FAILED_NOW,
            ProblemTiming::InPause => self::IN_PAUSE,
            ProblemTiming::Skipped => self::SKIPPED,
        };
    }

    /**
     * @param list<list<string>> $problems
     */
    private static function report(array $problems, int $dates, SymfonyStyle $io): int
    {
        if ([] === $problems) {
            $io->success(\sprintf('Prefetched dates: %d.', $dates));

            return Command::SUCCESS;
        }

        $io->table(self::PROBLEM_HEADERS, $problems);
        $io->error(\sprintf('Prefetched dates: %d, problems: %d.', $dates, \count($problems)));

        return Command::FAILURE;
    }
}
