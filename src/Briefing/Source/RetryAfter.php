<?php

declare(strict_types=1);

namespace App\Briefing\Source;

final readonly class RetryAfter
{
    public const string HEADER = 'retry-after';
    private const array HTTP_DATE_FORMATS = [
        'D, d M Y H:i:s \G\M\T',
        'l, d-M-y H:i:s \G\M\T',
        'D M j H:i:s Y',
    ];
    private const string UTC = 'UTC';
    private const string WHITESPACE_RUN = '/\s+/';
    private const string SPACE = ' ';
    private const int LONGEST_WAIT_SECONDS = 86400;

    public static function parse(string $value, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $retryAt = self::retryAt(self::normalized($value), $now);

        if ($retryAt instanceof \DateTimeImmutable) {
            return $retryAt->setTimezone(new \DateTimeZone(self::UTC));
        }

        return null;
    }

    private static function retryAt(string $retryAfter, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (ctype_digit($retryAfter)) {
            return self::afterSeconds($retryAfter, $now);
        }

        $date = self::httpDate($retryAfter);

        if ($date instanceof \DateTimeImmutable) {
            return self::withinTheLongestWait($date, $now);
        }

        return null;
    }

    private static function afterSeconds(string $retryAfter, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return self::after($now, min((int) $retryAfter, self::LONGEST_WAIT_SECONDS));
    }

    private static function normalized(string $value): string
    {
        return (string) preg_replace(self::WHITESPACE_RUN, self::SPACE, trim($value));
    }

    private static function httpDate(string $value): ?\DateTimeImmutable
    {
        foreach (self::HTTP_DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone(self::UTC));

            if (self::isExactly($date, $format, $value)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true \DateTimeImmutable $date
     */
    private static function isExactly(\DateTimeImmutable|false $date, string $format, string $value): bool
    {
        if ($date instanceof \DateTimeImmutable) {
            return $date->format($format) === $value;
        }

        return false;
    }

    private static function withinTheLongestWait(\DateTimeImmutable $date, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return min(max($date, $now), self::after($now, self::LONGEST_WAIT_SECONDS));
    }

    private static function after(\DateTimeImmutable $now, int $seconds): \DateTimeImmutable
    {
        return $now->modify("+{$seconds} seconds");
    }
}
