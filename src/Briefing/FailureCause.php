<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;

final readonly class FailureCause
{
    private const string DEADLINE_EXCEEDED = 'DeadlineExceeded';

    /**
     * @param list<string>|null $fields
     */
    public function __construct(
        public string $errorType,
        public ?array $fields = null,
        public ?PayloadDefect $defect = null,
    ) {
    }

    public static function deadlineExceeded(): self
    {
        return new self(self::DEADLINE_EXCEEDED);
    }

    public static function of(\Throwable $exception): self
    {
        $errorType = new \ReflectionClass($exception)->getShortName();

        if ($exception instanceof InvalidUpstreamPayloadException) {
            return new self($errorType, $exception->fields(), $exception->defect());
        }

        return new self($errorType);
    }
}
