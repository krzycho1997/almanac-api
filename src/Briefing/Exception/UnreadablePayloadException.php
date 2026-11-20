<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

use App\Briefing\SourceName;

final class UnreadablePayloadException extends \UnexpectedValueException
{
    public static function forSource(SourceName $source, \Throwable $cause): self
    {
        return new self("The stored {$source->value} payload no longer matches the model.", previous: $cause);
    }
}
