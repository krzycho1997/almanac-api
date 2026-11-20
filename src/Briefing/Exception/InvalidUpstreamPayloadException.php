<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class InvalidUpstreamPayloadException extends \UnexpectedValueException
{
    private const string PATH_SEPARATOR = ', ';
    private const string AT = ' at ';
    private const string NOWHERE = '';

    /**
     * @param list<string> $fields
     */
    private function __construct(
        string $message,
        private readonly PayloadDefect $defect,
        private readonly array $fields,
    ) {
        parent::__construct($message);
    }

    public static function at(string $field, PayloadDefect $defect, string $reason): self
    {
        return new self($reason, $defect, [$field]);
    }

    public static function notJson(string $type): self
    {
        return new self("Upstream response for {$type} is not valid JSON.", PayloadDefect::NotJson, []);
    }

    /**
     * @param list<string> $paths
     */
    public static function doesNotMatch(string $type, array $paths): self
    {
        return new self("Upstream response does not match {$type}".self::located($paths).'.', PayloadDefect::WrongShape, $paths);
    }

    /**
     * @param list<string> $paths
     */
    public static function isInvalidAt(string $type, array $paths): self
    {
        return new self("Upstream response {$type} is invalid".self::located($paths).'.', PayloadDefect::InvalidValue, $paths);
    }

    public function defect(): PayloadDefect
    {
        return $this->defect;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
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
