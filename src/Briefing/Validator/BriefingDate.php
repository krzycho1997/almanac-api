<?php

declare(strict_types=1);

namespace App\Briefing\Validator;

use App\Briefing\BriefingDay;
use Symfony\Component\Validator\Constraints as Assert;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class BriefingDate extends Assert\Compound
{
    public const int DAYS_AHEAD = 15;
    public const string LAST_DAY = 'today +'.self::DAYS_AHEAD.' days';
    public const string OUTSIDE_WINDOW = 'The date must be between '.BriefingDay::FIRST.' and '.self::DAYS_AHEAD.' days after today.';

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Assert\Range>
     */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Range(notInRangeMessage: self::OUTSIDE_WINDOW, min: BriefingDay::FIRST, max: self::LAST_DAY)];
    }
}
