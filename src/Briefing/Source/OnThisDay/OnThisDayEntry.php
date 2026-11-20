<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final readonly class OnThisDayEntry
{
    private const float FURTHEST_YEAR = 100000.0;

    /**
     * @param list<OnThisDayPage> $pages
     */
    public function __construct(
        public string $text,
        public float $year,
        public array $pages = [],
    ) {
    }

    public function wholeYear(): ?int
    {
        if (self::isWholeYear($this->year)) {
            return (int) $this->year;
        }

        return null;
    }

    private static function isWholeYear(float $year): bool
    {
        if (abs($year) > self::FURTHEST_YEAR) {
            return false;
        }

        return floor($year) === $year;
    }
}
