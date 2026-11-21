<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Exception\InvalidDateRangeException;

/**
 * @implements \IteratorAggregate<int, \DateTimeImmutable>
 */
final readonly class DateRange implements \IteratorAggregate, \Countable
{
    private const string NEXT_DAY = '+1 day';
    private const int BOTH_ENDS = 1;

    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    public static function between(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $first = $from->setTime(0, 0);
        $last = new \DateTimeImmutable($to->format(BriefingDay::FORMAT), $from->getTimezone());

        if ($first->format(BriefingDay::FORMAT) <= $last->format(BriefingDay::FORMAT)) {
            return new self($first, $last);
        }

        throw InvalidDateRangeException::endsBeforeItStarts();
    }

    public function count(): int
    {
        /** @var int<0, max> $daysBetween */
        $daysBetween = $this->from->diff($this->to)->days;

        return $daysBetween + self::BOTH_ENDS;
    }

    /**
     * @return \Generator<int, \DateTimeImmutable>
     */
    public function getIterator(): \Generator
    {
        for ($day = $this->from; $day->format(BriefingDay::FORMAT) <= $this->to->format(BriefingDay::FORMAT); $day = $day->modify(self::NEXT_DAY)->setTime(0, 0)) {
            yield $day;
        }
    }
}
