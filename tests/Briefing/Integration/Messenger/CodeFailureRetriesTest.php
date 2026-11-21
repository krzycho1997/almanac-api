<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Messenger;

use App\Briefing\MessageHandler\PrefetchRetryStrategy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class CodeFailureRetriesTest extends KernelTestCase
{
    public function testRetriesAFailingMessageThreeTimesAfterOneFourAndSixteenSeconds(): void
    {
        // Given
        $strategy = self::getContainer()->get(PrefetchRetryStrategy::CODE_FAILURES);
        $failure = new \LogicException('A bug.');

        // When
        $retries = array_map(static fn (int $count): array => [
            $strategy->isRetryable(self::retried($count), $failure),
            $strategy->getWaitingTime(self::retried($count), $failure),
        ], [0, 1, 2, 3]);

        // Then
        self::assertSame([[true, 1_000], [true, 4_000], [true, 16_000], [false, 64_000]], $retries);
    }

    private static function retried(int $count): Envelope
    {
        $envelope = new Envelope(new \stdClass());

        if (0 === $count) {
            return $envelope;
        }

        return $envelope->with(new RedeliveryStamp($count));
    }
}
