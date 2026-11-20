<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class UpstreamStreamException extends \RuntimeException
{
    private function __construct(
        public readonly \Throwable $failure,
    ) {
        parent::__construct($failure->getMessage(), previous: $failure);
    }

    public static function brokenBy(\Throwable $failure): self
    {
        return new self($failure);
    }
}
