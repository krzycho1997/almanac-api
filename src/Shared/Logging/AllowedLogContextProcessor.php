<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class AllowedLogContextProcessor implements ProcessorInterface
{
    public const string MASK = '[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: self::allowedOnly($record->context));
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function allowedOnly(array $context): array
    {
        $allowed = [];

        foreach ($context as $key => $value) {
            $allowed[$key] = self::isAllowed($key) ? $value : self::MASK;
        }

        return $allowed;
    }

    private static function isAllowed(int|string $key): bool
    {
        return LogContextKey::tryFrom((string) $key) instanceof LogContextKey;
    }
}
