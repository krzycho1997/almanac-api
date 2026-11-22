<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\Dto\Request\BriefingQuery;
use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingQueryMapper;
use App\Briefing\Mapper\CoordinateMapper;
use PHPUnit\Framework\TestCase;

final class BriefingQueryMapperTest extends TestCase
{
    public function testConvertsTheCoordinatesToALocation(): void
    {
        // When
        $location = new BriefingQueryMapper(new CoordinateMapper())->convertToLocation(new BriefingQuery('50.06', '19.94'), self::warsaw());

        // Then
        self::assertEquals(Location::fromCoordinates(50.06, 19.94), $location);
    }

    public function testUsesTheDefaultLocationWithoutCoordinates(): void
    {
        // Given
        $default = self::warsaw();

        // When
        $location = new BriefingQueryMapper(new CoordinateMapper())->convertToLocation(new BriefingQuery(), $default);

        // Then
        self::assertSame($default, $location);
    }

    public function testRefusesASingleCoordinate(): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);

        new BriefingQueryMapper(new CoordinateMapper())->convertToLocation(new BriefingQuery('50.06'), self::warsaw());
    }

    private static function warsaw(): Location
    {
        return Location::defaultAt(52.23, 21.01, 'Warsaw');
    }
}
