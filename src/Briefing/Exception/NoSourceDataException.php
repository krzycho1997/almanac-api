<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class NoSourceDataException extends \RuntimeException
{
    public static function forBriefing(): self
    {
        return new self('No source has data for this briefing.');
    }
}
