<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\Dto\Response\SourceKey;
use App\Briefing\SourceName;

final class SourceKeyMapper
{
    public static function keyOf(SourceName $source): SourceKey
    {
        return match ($source) {
            SourceName::Apod => SourceKey::Apod,
            SourceName::OnThisDay => SourceKey::OnThisDay,
            SourceName::Weather => SourceKey::Weather,
        };
    }
}
