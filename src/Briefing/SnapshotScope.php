<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class SnapshotScope
{
    public const int LONGEST_KEY = 64;

    private function __construct(
        public SourceName $source,
        public string $key,
        public ?\DateTimeImmutable $date,
    ) {
        if ('' === $key) {
            throw new \InvalidArgumentException('A snapshot key cannot be empty.');
        }

        if (mb_strlen($key) > self::LONGEST_KEY) {
            throw new \InvalidArgumentException(\sprintf('A snapshot key has at most %d characters.', self::LONGEST_KEY));
        }
    }

    public static function dated(SourceName $source, string $key, \DateTimeImmutable $date): self
    {
        return new self($source, $key, $date);
    }

    public static function undated(SourceName $source, string $key): self
    {
        return new self($source, $key, null);
    }
}
