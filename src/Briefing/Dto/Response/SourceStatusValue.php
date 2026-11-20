<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum SourceStatusValue: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
}
