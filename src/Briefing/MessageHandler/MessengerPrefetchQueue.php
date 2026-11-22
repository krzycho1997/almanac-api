<?php

declare(strict_types=1);

namespace App\Briefing\MessageHandler;

use App\Briefing\Location;
use App\Briefing\PrefetchQueue;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsAlias(PrefetchQueue::class)]
final readonly class MessengerPrefetchQueue implements PrefetchQueue
{
    public function __construct(
        private MessageBusInterface $bus,
        private PrefetchDateMessageMapper $mapper,
    ) {
    }

    public function enqueue(\DateTimeImmutable $date, Location $location, int $delayMs): void
    {
        $this->bus->dispatch($this->mapper->convertToMessage($date, $location), [new DelayStamp($delayMs)]);
    }
}
