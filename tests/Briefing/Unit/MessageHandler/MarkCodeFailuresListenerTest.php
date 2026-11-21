<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\MessageHandler;

use App\Briefing\Exception\DateNotReadyException;
use App\Briefing\MessageHandler\CodeFailureStamp;
use App\Briefing\MessageHandler\MarkCodeFailuresListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class MarkCodeFailuresListenerTest extends TestCase
{
    #[DataProvider('failures')]
    public function testMarksEveryFailureButADateThatIsNotReady(\Throwable $thrown, int $marks): void
    {
        // Given
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, ['handler' => $thrown]));

        // When
        new MarkCodeFailuresListener()($event);

        // Then
        self::assertCount($marks, $event->getEnvelope()->all(CodeFailureStamp::class));
    }

    /**
     * @return iterable<string, array{\Throwable, int}>
     */
    public static function failures(): iterable
    {
        yield 'a bug' => [new \LogicException('A bug.'), 1];
        yield 'a date that waits for its upstream' => [DateNotReadyException::until(new \DateTimeImmutable('2026-11-20 12:01')), 0];
    }
}
