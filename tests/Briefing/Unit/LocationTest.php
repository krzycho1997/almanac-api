<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Location;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocationTest extends TestCase
{
    private const float LATITUDE = 52.23;
    private const float LONGITUDE = 21.01;
    private const string NAME = 'Warsaw';

    #[DataProvider('coordinates')]
    public function testRoundsCoordinatesToAboutOneKilometre(float $latitude, float $longitude, float $expectedLatitude, float $expectedLongitude): void
    {
        // When
        $location = Location::fromCoordinates($latitude, $longitude);

        // Then
        self::assertSame($expectedLatitude, $location->latitude);
        self::assertSame($expectedLongitude, $location->longitude);
    }

    /**
     * @return iterable<string, array{float, float, float, float}>
     */
    public static function coordinates(): iterable
    {
        yield 'Warsaw' => [52.2297, 21.0122, 52.23, 21.01];
        yield 'half rounds away from zero' => [52.235, -21.015, 52.24, -21.02];
        yield 'half after an even digit still rounds away from zero' => [52.225, 21.005, 52.23, 21.01];
        yield 'already rounded' => [-33.87, 151.21, -33.87, 151.21];
        yield 'poles and antimeridian' => [90.0, 180.0, 90.0, 180.0];
        yield 'rounds up to the pole' => [89.996, 0.0, 90.0, 0.0];
    }

    #[DataProvider('signedZeroAndAntimeridian')]
    public function testGivesOnePlaceOneSpelling(float $latitude, float $longitude, string $expectedJson): void
    {
        // When
        $location = Location::fromCoordinates($latitude, $longitude);

        // Then
        self::assertSame($expectedJson, json_encode([$location->latitude, $location->longitude], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return iterable<string, array{float, float, string}>
     */
    public static function signedZeroAndAntimeridian(): iterable
    {
        yield 'negative zero after rounding' => [-0.001, -0.004, '[0.0,0.0]'];
        yield 'western antimeridian' => [0.0, -180.0, '[0.0,180.0]'];
        yield 'rounds onto the western antimeridian' => [0.0, -179.996, '[0.0,180.0]'];
    }

    #[DataProvider('outOfRange')]
    public function testRejectsCoordinatesOutsideTheGlobe(float $latitude, float $longitude): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);
        Location::fromCoordinates($latitude, $longitude);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function outOfRange(): iterable
    {
        yield 'latitude above 90' => [90.01, 0.0];
        yield 'latitude below -90' => [-90.01, 0.0];
        yield 'longitude above 180' => [0.0, 180.01];
        yield 'longitude below -180' => [0.0, -180.01];
        yield 'latitude just above 90 is not rounded back in' => [90.004, 0.0];
        yield 'longitude just above 180 is not rounded back in' => [0.0, 180.004];
        yield 'latitude not a number' => [\NAN, 0.0];
        yield 'longitude not a number' => [0.0, \NAN];
        yield 'latitude infinite' => [-\INF, 0.0];
        yield 'longitude infinite' => [0.0, \INF];
    }

    public function testUsesTheDefaultWithoutCoordinates(): void
    {
        // Given
        $default = Location::defaultAt(self::LATITUDE, self::LONGITUDE, self::NAME);

        // When
        $location = Location::fromOptional(null, null, $default);

        // Then
        self::assertSame($default, $location);
        self::assertTrue($location->isDefault);
        self::assertSame(self::NAME, $location->name);
    }

    public function testTreatsGivenCoordinatesAsNotDefaultEvenAtTheDefaultPlace(): void
    {
        // Given
        $default = Location::defaultAt(self::LATITUDE, self::LONGITUDE, self::NAME);

        // When
        $location = Location::fromOptional(52.2297, 21.0122, $default);

        // Then
        self::assertEquals(Location::fromCoordinates(self::LATITUDE, self::LONGITUDE), $location);
        self::assertFalse($location->isDefault);
        self::assertNull($location->name);
    }

    #[DataProvider('partialCoordinates')]
    public function testRejectsOnlyOneCoordinate(?float $latitude, ?float $longitude): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);
        Location::fromOptional($latitude, $longitude, Location::defaultAt(self::LATITUDE, self::LONGITUDE, self::NAME));
    }

    /**
     * @return iterable<string, array{?float, ?float}>
     */
    public static function partialCoordinates(): iterable
    {
        yield 'latitude only' => [52.23, null];
        yield 'longitude only' => [null, 21.01];
    }

    public function testRoundsTheDefaultToo(): void
    {
        // When
        $location = Location::defaultAt(52.2297, 21.0122, self::NAME);

        // Then
        self::assertSame(self::LATITUDE, $location->latitude);
        self::assertSame(self::LONGITUDE, $location->longitude);
    }

    #[DataProvider('outOfRange')]
    public function testRejectsADefaultOutsideTheGlobe(float $latitude, float $longitude): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);
        Location::defaultAt($latitude, $longitude, self::NAME);
    }

    public function testTrimsTheDefaultName(): void
    {
        // When
        $location = Location::defaultAt(self::LATITUDE, self::LONGITUDE, " Warsaw\n");

        // Then
        self::assertSame(self::NAME, $location->name);
    }

    #[DataProvider('blankNames')]
    public function testRejectsADefaultWithoutAName(string $name): void
    {
        // Then / When
        $this->expectException(InvalidLocationException::class);
        Location::defaultAt(self::LATITUDE, self::LONGITUDE, $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
    }

    public function testKeepsCoordinatesOutOfTheExceptionMessage(): void
    {
        // Given
        $message = '';

        // When
        try {
            Location::fromCoordinates(91.2345, 21.0122);
        } catch (InvalidLocationException $exception) {
            $message = $exception->getMessage();
        }

        // Then
        self::assertNotSame('', $message);
        self::assertStringNotContainsString('91.2345', $message);
        self::assertStringNotContainsString('21.0122', $message);
    }

    public function testRebuildsTheSameLocationFromItsRoundedCoordinates(): void
    {
        // Given
        $location = Location::fromCoordinates(52.2297, -21.0149);

        // When
        $rebuilt = Location::fromCoordinates($location->latitude, $location->longitude);

        // Then
        self::assertEquals($location, $rebuilt);
    }
}
