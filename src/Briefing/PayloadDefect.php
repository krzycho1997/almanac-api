<?php

declare(strict_types=1);

namespace App\Briefing;

enum PayloadDefect: string
{
    case NotJson = 'not_json';
    case WrongShape = 'wrong_shape';
    case InvalidValue = 'invalid_value';
    case Placeholder = 'placeholder';
    case OtherDate = 'other_date';
    case NoText = 'no_text';
    case NotACalendarDate = 'not_a_calendar_date';
}
