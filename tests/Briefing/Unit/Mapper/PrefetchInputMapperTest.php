<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\Dto\Input\PrefetchInput;
use App\Briefing\Location;
use App\Briefing\Mapper\CoordinateMapper;
use App\Briefing\Mapper\PrefetchInputMapper;
use App\Briefing\Validator\ValidPrefetchRange;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PrefetchInputMapperTest extends TestCase
{
    public function testAcceptsARangeOfExactlyTheLongestLength(): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = '2024-01-01';
        $input->to = '2024-12-31';

        // When
        $violations = ValidatorWithClock::create(new MockClock('2026-11-21 10:00'))->validate($input);

        // Then
        self::assertCount(0, $violations);
        self::assertCount(ValidPrefetchRange::MAX_DAYS, new PrefetchInputMapper(new CoordinateMapper())->convertToRange($input, new \DateTimeZone('UTC')));
    }

    public function testConvertsTheDatesToDaysInTheGivenTimezone(): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = '2024-03-15';
        $input->to = '2024-03-16';

        // When
        $range = new PrefetchInputMapper(new CoordinateMapper())->convertToRange($input, new \DateTimeZone('Europe/Warsaw'));

        // Then
        self::assertEquals(new \DateTimeImmutable('2024-03-15 00:00', new \DateTimeZone('Europe/Warsaw')), $range->from);
        self::assertCount(2, $range);
    }

    public function testRefusesDatesThatSkippedValidation(): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = '2024-02-30';
        $input->to = '2024-03-01';

        // Then / When
        $this->expectException(\LogicException::class);

        new PrefetchInputMapper(new CoordinateMapper())->convertToRange($input, new \DateTimeZone('UTC'));
    }

    public function testConvertsThePlaceOrFallsBackToTheDefault(): void
    {
        // Given
        $default = Location::defaultAt(52.23, 21.01, 'Warsaw');
        $somewhere = new PrefetchInput();
        $somewhere->lat = '50.06';
        $somewhere->lon = '19.94';
        $mapper = new PrefetchInputMapper(new CoordinateMapper());

        // When
        $locations = [$mapper->convertToLocation($somewhere, $default), $mapper->convertToLocation(new PrefetchInput(), $default)];

        // Then
        self::assertEquals([Location::fromCoordinates(50.06, 19.94), $default], $locations);
    }

    public function testConvertsTheDelayToMilliseconds(): void
    {
        // Given
        $input = new PrefetchInput();
        $input->delay = '250';

        // When
        $delay = new PrefetchInputMapper(new CoordinateMapper())->convertToDelayMs($input);

        // Then
        self::assertSame(250, $delay);
    }
}
