<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use Psr\Clock\ClockInterface;

final class FrozenClockTest extends FunctionalTestCase
{
    public function testAppClockReturnsTheFrozenTestTime(): void
    {
        // Given
        $clock = self::getContainer()->get(ClockInterface::class);

        // When
        $now = $clock->now();

        // Then
        self::assertSame(self::NOW, $now->format('Y-m-d H:i:s'));
        self::assertSame(self::TIMEZONE, $now->getTimezone()->getName());
    }
}
