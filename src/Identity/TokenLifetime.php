<?php

declare(strict_types=1);

namespace App\Identity;

final readonly class TokenLifetime
{
    public const string FORMAT = '/^(?<amount>[1-9][0-9]{0,5})(?<unit>[hd])$/D';
    private const int MAX_HOURS = 365 * self::HOURS_PER_DAY;
    private const string HOURS = 'h';
    private const string DAYS = 'd';
    private const int HOURS_PER_DAY = 24;
    private const string HOURS_INTERVAL = 'PT%dH';

    private function __construct(
        public int $hours,
    ) {
    }

    public static function parse(string $text): ?self
    {
        if (1 === preg_match(self::FORMAT, $text, $match)) {
            return self::within((int) $match['amount'] * self::hoursPer($match['unit']));
        }

        return null;
    }

    public static function isWellFormed(string $text): bool
    {
        return 1 === preg_match(self::FORMAT, $text);
    }

    public function interval(): \DateInterval
    {
        return new \DateInterval(\sprintf(self::HOURS_INTERVAL, $this->hours));
    }

    private static function within(int $hours): ?self
    {
        if ($hours > self::MAX_HOURS) {
            return null;
        }

        return new self($hours);
    }

    private static function hoursPer(string $unit): int
    {
        return match ($unit) {
            self::HOURS => 1,
            self::DAYS => self::HOURS_PER_DAY,
            default => throw new \LogicException('The lifetime format allows only hours and days.'),
        };
    }
}
