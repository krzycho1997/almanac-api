<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class InvalidDateRangeException extends \InvalidArgumentException
{
    public const string ENDS_BEFORE_IT_STARTS = 'The last date must not be before the first.';

    public static function endsBeforeItStarts(): self
    {
        return new self(self::ENDS_BEFORE_IT_STARTS);
    }
}
