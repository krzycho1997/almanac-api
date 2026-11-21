<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class SnapshotScope
{
    public const int LONGEST_KEY = 64;

    public function __construct(
        public SourceName $source,
        public string $key,
        public ?\DateTimeImmutable $date,
    ) {
        self::assertKeyFits($key);
        self::assertDatedLike($source, $date);
    }

    private static function assertKeyFits(string $key): void
    {
        if ('' === $key) {
            throw new \InvalidArgumentException('A snapshot key cannot be empty.');
        }

        if (mb_strlen($key) > self::LONGEST_KEY) {
            throw new \InvalidArgumentException(\sprintf('A snapshot key has at most %d characters.', self::LONGEST_KEY));
        }
    }

    private static function assertDatedLike(SourceName $source, ?\DateTimeImmutable $date): void
    {
        if (SourceName::OnThisDay === $source) {
            self::assertUndated($source, $date);

            return;
        }

        self::assertDated($source, $date);
    }

    private static function assertUndated(SourceName $source, ?\DateTimeImmutable $date): void
    {
        if ($date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(\sprintf('Snapshots of %s have no date.', $source->value));
        }
    }

    private static function assertDated(SourceName $source, ?\DateTimeImmutable $date): void
    {
        if (null === $date) {
            throw new \InvalidArgumentException(\sprintf('Snapshots of %s need a date.', $source->value));
        }
    }
}
