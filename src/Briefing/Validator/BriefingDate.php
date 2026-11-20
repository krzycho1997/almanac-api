<?php

declare(strict_types=1);

namespace App\Briefing\Validator;

use Symfony\Component\Validator\Constraints as Assert;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class BriefingDate extends Assert\Compound
{
    public const string FIRST_DAY = '1995-06-16';
    public const string LAST_DAY = 'today +15 days';
    public const string OUTSIDE_WINDOW = 'The date must be between '.self::FIRST_DAY.' and 15 days after today.';

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Assert\Range>
     */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Range(notInRangeMessage: self::OUTSIDE_WINDOW, min: self::FIRST_DAY, max: self::LAST_DAY)];
    }
}
