<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum ApodMediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Other = 'other';
}
