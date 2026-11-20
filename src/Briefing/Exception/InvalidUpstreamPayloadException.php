<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class InvalidUpstreamPayloadException extends \UnexpectedValueException
{
    private const string PATH_SEPARATOR = ', ';
    private const string AT = ' at ';
    private const string NOWHERE = '';

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function notJson(string $type): self
    {
        return new self("Upstream response for {$type} is not valid JSON.");
    }

    /**
     * @param list<string> $paths
     */
    public static function doesNotMatch(string $type, array $paths): self
    {
        return new self("Upstream response does not match {$type}".self::located($paths).'.');
    }

    /**
     * @param list<string> $paths
     */
    public static function isInvalidAt(string $type, array $paths): self
    {
        return new self("Upstream response {$type} is invalid".self::located($paths).'.');
    }

    /**
     * @param list<string> $paths
     */
    private static function located(array $paths): string
    {
        if ([] === $paths) {
            return self::NOWHERE;
        }

        return self::AT.implode(self::PATH_SEPARATOR, $paths);
    }
}
