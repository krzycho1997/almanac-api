<?php

declare(strict_types=1);

namespace App\Briefing\MessageHandler;

use App\Briefing\BriefingAggregator;
use App\Briefing\Exception\DateNotReadyException;
use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;
use App\Briefing\Messenger\PrefetchDateMessageMapper;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class PrefetchDateHandler
{
    private const string NOT_A_DAY = 'The queued date is not a Y-m-d day.';
    private const string NOT_A_PLACE = 'The queued place is not a valid location.';

    public function __construct(
        private BriefingAggregator $aggregator,
        private ClockInterface $clock,
        private PrefetchDateMessageMapper $messageMapper,
        #[Target('defaultLocation')]
        private Location $defaultLocation,
    ) {
    }

    public function __invoke(PrefetchDateMessage $message): void
    {
        $briefing = $this->aggregator->briefingFor($this->dayOf($message), $this->locationOf($message));
        $incompleteUntil = $briefing->incompleteUntil();

        if ($incompleteUntil instanceof \DateTimeImmutable) {
            throw DateNotReadyException::until($incompleteUntil);
        }
    }

    private function locationOf(PrefetchDateMessage $message): Location
    {
        try {
            return $this->messageMapper->convertToLocation($message, $this->defaultLocation);
        } catch (InvalidLocationException $invalid) {
            throw new UnrecoverableMessageHandlingException(self::NOT_A_PLACE, previous: $invalid);
        }
    }

    private function dayOf(PrefetchDateMessage $message): \DateTimeImmutable
    {
        return $this->messageMapper->convertToDay($message, $this->clock->now()->getTimezone())
            ?? throw new UnrecoverableMessageHandlingException(self::NOT_A_DAY);
    }
}
