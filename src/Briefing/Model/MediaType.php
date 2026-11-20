<?php

declare(strict_types=1);

namespace App\Briefing\Model;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Other = 'other';
}
