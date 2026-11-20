<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class InvalidUpstreamPayloadException extends \UnexpectedValueException
{
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self($reason, previous: $previous);
    }
}
