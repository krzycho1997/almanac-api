<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\MessageHandler;

use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;
use App\Briefing\MessageHandler\MessengerPrefetchQueue;
use App\Briefing\MessageHandler\PrefetchDateMessageMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class MessengerPrefetchQueueTest extends TestCase
{
    public function testDispatchesTheMessageWithTheGivenDelay(): void
    {
        // Given
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $this->sent[] = $envelope;

                return $envelope;
            }
        };

        // When
        new MessengerPrefetchQueue($bus, new PrefetchDateMessageMapper())->enqueue(new \DateTimeImmutable('2024-03-15'), Location::fromCoordinates(50.06, 19.94), 2000);

        // Then
        self::assertCount(1, $bus->sent);
        self::assertEquals(new PrefetchDateMessage('2024-03-15', 50.06, 19.94), $bus->sent[0]->getMessage());
        self::assertSame(2000, $bus->sent[0]->last(DelayStamp::class)?->getDelay());
    }
}
