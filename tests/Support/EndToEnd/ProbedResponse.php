<?php

declare(strict_types=1);

namespace App\Tests\Support\EndToEnd;

final readonly class ProbedResponse
{
    private const string STATUS_LINE = '/^HTTP\/\S+ (\d{3})/';
    private const string HEADER_SEPARATOR = ':';

    /**
     * @param list<string> $headerLines
     */
    private function __construct(
        public int $status,
        private array $headerLines,
    ) {
    }

    /**
     * @param list<string> $rawHeaders
     */
    public static function fromRawHeaders(array $rawHeaders): self
    {
        $statusLine = array_shift($rawHeaders) ?? '';

        return new self(self::statusFrom($statusLine), $rawHeaders);
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        $matchingLines = array_filter($this->headerLines, static fn (string $line): bool => self::isNamed($line, $name));

        return array_values(array_map(self::valueOf(...), $matchingLines));
    }

    private static function statusFrom(string $statusLine): int
    {
        if (1 === preg_match(self::STATUS_LINE, $statusLine, $matches)) {
            return (int) $matches[1];
        }

        throw new \UnexpectedValueException("No HTTP status line in \"{$statusLine}\".");
    }

    private static function isNamed(string $line, string $name): bool
    {
        return 0 === strcasecmp(explode(self::HEADER_SEPARATOR, $line, 2)[0], $name);
    }

    private static function valueOf(string $line): string
    {
        return trim(explode(self::HEADER_SEPARATOR, $line, 2)[1] ?? '');
    }
}
