<?php

declare(strict_types=1);

namespace App\Briefing\MessageHandler;

use App\Briefing\Exception\DateNotReadyException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: self::BEFORE_THE_RETRY)]
final readonly class MarkCodeFailuresListener
{
    private const int BEFORE_THE_RETRY = 150;

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (DateNotReadyException::in($event->getThrowable()) instanceof DateNotReadyException) {
            return;
        }

        $event->addStamps(new CodeFailureStamp());
    }
}
