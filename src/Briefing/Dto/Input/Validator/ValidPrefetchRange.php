<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Input\Validator;

use App\Briefing\Exception\InvalidDateRangeException;
use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidPrefetchRange extends Constraint
{
    public const int MAX_DAYS = 366;
    public const string ENDS_BEFORE_IT_STARTS_ERROR = '68e51914-a163-450e-a64f-c95764136e94';
    public const string TOO_LONG_ERROR = 'a942a216-9cd4-4e37-bfc8-31660a6360fb';

    protected const array ERROR_NAMES = [
        self::ENDS_BEFORE_IT_STARTS_ERROR => 'ENDS_BEFORE_IT_STARTS_ERROR',
        self::TOO_LONG_ERROR => 'TOO_LONG_ERROR',
    ];

    public string $endsBeforeItStartsMessage = InvalidDateRangeException::ENDS_BEFORE_IT_STARTS;
    public string $tooLongMessage = 'The range may span at most '.self::MAX_DAYS.' days.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
