<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Messenger;

use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;
use App\Briefing\Messenger\PrefetchDateMessageMapper;
use PHPUnit\Framework\TestCase;

final class PrefetchDateMessageMapperTest extends TestCase
{
    public function testCarriesTheDayAndThePlaceAsPlainValues(): void
    {
        // Given
        $mapper = new PrefetchDateMessageMapper();

        // When
        $message = $mapper->convertToMessage(new \DateTimeImmutable('2024-03-15 13:45', new \DateTimeZone('Europe/Warsaw')), Location::fromCoordinates(50.06, 19.94));

        // Then
        self::assertSame(['2024-03-15', 50.06, 19.94], [$message->date, $message->latitude, $message->longitude]);
        self::assertEquals(new \DateTimeImmutable('2024-03-15 00:00', new \DateTimeZone('Europe/Warsaw')), $mapper->convertToDay($message, new \DateTimeZone('Europe/Warsaw')));
        self::assertEquals(Location::fromCoordinates(50.06, 19.94), $mapper->convertToLocation($message, Location::defaultAt(52.23, 21.01, 'Warsaw')));
    }

    public function testLeavesTheDefaultPlaceToTheWorker(): void
    {
        // Given
        $mapper = new PrefetchDateMessageMapper();
        $default = Location::defaultAt(52.23, 21.01, 'Warsaw');

        // When
        $message = $mapper->convertToMessage(new \DateTimeImmutable('2024-03-15'), $default);

        // Then
        self::assertSame([null, null], [$message->latitude, $message->longitude]);
        self::assertSame($default, $mapper->convertToLocation($message, $default));
    }

    public function testKeepsTheCalendarDayOfTheDateInItsOwnTimezone(): void
    {
        // When
        $message = new PrefetchDateMessageMapper()->convertToMessage(new \DateTimeImmutable('2024-03-15 00:30', new \DateTimeZone('Europe/Warsaw')), Location::fromCoordinates(50.06, 19.94));

        // Then
        self::assertSame('2024-03-15', $message->date);
    }

    public function testTellsAQueuedValueThatIsNoDay(): void
    {
        // When
        $day = new PrefetchDateMessageMapper()->convertToDay(new PrefetchDateMessage('2024-02-30', null, null), new \DateTimeZone('UTC'));

        // Then
        self::assertNull($day);
    }
}
