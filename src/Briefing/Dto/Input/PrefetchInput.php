<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Input;

use App\Briefing\BriefingDay;
use App\Briefing\DateRange;
use App\Briefing\Exception\InvalidDateRangeException;
use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Location;
use App\Briefing\Validator\BriefingDayText;
use App\Briefing\Validator\Latitude;
use App\Briefing\Validator\Longitude;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PrefetchInput
{
    public const int MAX_DAYS = 366;
    public const int MAX_DELAY_MS = 600_000;
    private const string UTC = 'UTC';
    private const string WHOLE_NUMBER = '/^\d+$/';
    private const string NOT_WHOLE_MILLISECONDS = 'The delay must be a whole number of milliseconds.';
    private const string TO = 'to';
    private const string TOO_LONG = 'The range may span at most '.self::MAX_DAYS.' days.';

    #[Argument(description: 'First date to prefetch, Y-m-d')]
    #[Assert\NotBlank]
    #[Assert\Date]
    #[BriefingDayText]
    public string $from;

    #[Argument(description: 'Last date to prefetch, Y-m-d, inclusive')]
    #[Assert\NotBlank]
    #[Assert\Date]
    #[BriefingDayText]
    public string $to;

    #[Option(description: 'Latitude of the place; the default place when left out together with --lon')]
    #[Latitude]
    #[Assert\When(static function (self $input): bool {
        return null !== $input->lon;
    }, constraints: new Assert\NotNull(message: InvalidLocationException::ONLY_ONE_COORDINATE))]
    public ?string $lat = null;

    #[Option(description: 'Longitude of the place; the default place when left out together with --lat')]
    #[Longitude]
    #[Assert\When(static function (self $input): bool {
        return null !== $input->lat;
    }, constraints: new Assert\NotNull(message: InvalidLocationException::ONLY_ONE_COORDINATE))]
    public ?string $lon = null;

    #[Option(description: 'Pause after a date that called upstream, in milliseconds; with --async, the spacing of the queued dates')]
    #[Assert\Sequentially([
        new Assert\Regex(self::WHOLE_NUMBER, message: self::NOT_WHOLE_MILLISECONDS),
        new Assert\Range(min: 0, max: self::MAX_DELAY_MS),
    ])]
    public string $delay = '0';

    #[Option(description: 'Queue one message per date for the worker instead of fetching now')]
    public bool $async = false;

    #[Assert\Callback]
    public function validateRange(ExecutionContextInterface $context): void
    {
        if (null === BriefingDay::startOf($this->from, new \DateTimeZone(self::UTC))) {
            return;
        }

        if (null === BriefingDay::startOf($this->to, new \DateTimeZone(self::UTC))) {
            return;
        }

        if ($this->to < $this->from) {
            $context->buildViolation(InvalidDateRangeException::ENDS_BEFORE_IT_STARTS)->atPath(self::TO)->addViolation();

            return;
        }

        if (\count($this->toRange(new \DateTimeZone(self::UTC))) > self::MAX_DAYS) {
            $context->buildViolation(self::TOO_LONG)->atPath(self::TO)->addViolation();
        }
    }

    public function toRange(\DateTimeZone $timezone): DateRange
    {
        return DateRange::between(self::dayIn($this->from, $timezone), self::dayIn($this->to, $timezone));
    }

    public function locationOr(Location $default): Location
    {
        return Location::fromOptional(self::coordinate($this->lat), self::coordinate($this->lon), $default);
    }

    public function delayMs(): int
    {
        return (int) $this->delay;
    }

    private static function dayIn(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return BriefingDay::startOf($value, $timezone)
            ?? throw new \LogicException('The dates are read only after the input passed validation.');
    }

    private static function coordinate(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return (float) $value;
    }
}
