<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Location;
use App\Briefing\Mapper\CoordinateMapper;
use PHPUnit\Framework\TestCase;

final class CoordinateMapperTest extends TestCase
{
    public function testReadsCoordinatesGivenAsText(): void
    {
        // When
        $location = new CoordinateMapper()->convertToLocation('50.06', '19.94', self::warsaw());

        // Then
        self::assertEquals(Location::fromCoordinates(50.06, 19.94), $location);
    }

    public function testTreatsAnythingButTextAsMissing(): void
    {
        // Given
        $default = self::warsaw();

        // When
        $location = new CoordinateMapper()->convertToLocation(null, ['50.06'], $default);

        // Then
        self::assertSame($default, $location);
    }

    public function testRefusesASingleCoordinate(): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);

        new CoordinateMapper()->convertToLocation(null, '19.94', self::warsaw());
    }

    private static function warsaw(): Location
    {
        return Location::defaultAt(52.23, 21.01, 'Warsaw');
    }
}
