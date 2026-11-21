<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Message;

use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;
use PHPUnit\Framework\TestCase;

final class PrefetchDateMessageTest extends TestCase
{
    public function testCarriesTheDayAndThePlaceAsPlainValues(): void
    {
        // When
        $message = PrefetchDateMessage::of(new \DateTimeImmutable('2024-03-15 13:45', new \DateTimeZone('Europe/Warsaw')), Location::fromCoordinates(50.06, 19.94));

        // Then
        self::assertSame(['2024-03-15', 50.06, 19.94], [$message->date, $message->latitude, $message->longitude]);
        self::assertEquals(new \DateTimeImmutable('2024-03-15 00:00', new \DateTimeZone('Europe/Warsaw')), $message->dayIn(new \DateTimeZone('Europe/Warsaw')));
        self::assertEquals(Location::fromCoordinates(50.06, 19.94), $message->locationOr(Location::defaultAt(52.23, 21.01, 'Warsaw')));
    }

    public function testLeavesTheDefaultPlaceToTheWorker(): void
    {
        // Given
        $default = Location::defaultAt(52.23, 21.01, 'Warsaw');

        // When
        $message = PrefetchDateMessage::of(new \DateTimeImmutable('2024-03-15'), $default);

        // Then
        self::assertSame([null, null], [$message->latitude, $message->longitude]);
        self::assertSame($default, $message->locationOr($default));
    }

    public function testKeepsTheCalendarDayOfTheDateInItsOwnTimezone(): void
    {
        // When
        $message = PrefetchDateMessage::of(new \DateTimeImmutable('2024-03-15 00:30', new \DateTimeZone('Europe/Warsaw')), Location::fromCoordinates(50.06, 19.94));

        // Then
        self::assertSame('2024-03-15', $message->date);
    }

    public function testTellsAQueuedValueThatIsNoDay(): void
    {
        // When
        $day = new PrefetchDateMessage('2024-02-30', null, null)->dayIn(new \DateTimeZone('UTC'));

        // Then
        self::assertNull($day);
    }
}
