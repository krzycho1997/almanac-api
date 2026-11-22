<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Exception\InvalidLocationException;

final readonly class Location
{
    private const int PRECISION = 2;
    private const string KEY = '%.2F,%.2F';
    public const float MAX_LATITUDE = 90.0;
    public const float MAX_LONGITUDE = 180.0;

    private function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name,
        public bool $isDefault,
    ) {
    }

    public function key(): string
    {
        return \sprintf(self::KEY, $this->latitude, $this->longitude);
    }

    public static function fromCoordinates(float $latitude, float $longitude): self
    {
        return new self(self::latitude($latitude), self::longitude($longitude), null, false);
    }

    public static function defaultAt(float $latitude, float $longitude, string $name): self
    {
        return new self(self::latitude($latitude), self::longitude($longitude), self::defaultName($name), true);
    }

    public static function fromOptional(?float $latitude, ?float $longitude, self $default): self
    {
        if ([null, null] === [$latitude, $longitude]) {
            return $default;
        }

        if (null === $latitude) {
            throw InvalidLocationException::withOnlyOneCoordinate();
        }

        if (null === $longitude) {
            throw InvalidLocationException::withOnlyOneCoordinate();
        }

        return self::fromCoordinates($latitude, $longitude);
    }

    private static function latitude(float $latitude): float
    {
        if (abs($latitude) <= self::MAX_LATITUDE) {
            return self::rounded($latitude);
        }

        throw InvalidLocationException::latitudeOutsideTheGlobe();
    }

    private static function longitude(float $longitude): float
    {
        if (abs($longitude) <= self::MAX_LONGITUDE) {
            return self::onOneMeridian(self::rounded($longitude));
        }

        throw InvalidLocationException::longitudeOutsideTheGlobe();
    }

    private static function defaultName(string $name): string
    {
        $trimmed = trim($name);

        if ('' === $trimmed) {
            throw InvalidLocationException::defaultWithoutName();
        }

        return $trimmed;
    }

    private static function rounded(float $coordinate): float
    {
        return self::withoutNegativeZero(round($coordinate, self::PRECISION, \RoundingMode::HalfAwayFromZero));
    }

    private static function withoutNegativeZero(float $coordinate): float
    {
        return $coordinate + 0.0;
    }

    private static function onOneMeridian(float $longitude): float
    {
        if (-self::MAX_LONGITUDE === $longitude) {
            return self::MAX_LONGITUDE;
        }

        return $longitude;
    }
}
