<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;

final readonly class DiscoveryRecord
{
    private const string KEY_SEPARATOR = ':';

    public function __construct(
        public DiscoveryId $id,
        public string $key,
        public DiscoveryType $type,
        public ?\DateTimeImmutable $date,
        public string $title,
        public ?string $summary,
        public ?string $url,
    ) {
        self::assertTitled($title);
        self::assertKeyOf($type, $key);
        self::assertDatedWhenPicture($type, $date);
    }

    private static function assertTitled(string $title): void
    {
        if ('' === $title) {
            throw new \InvalidArgumentException('A discovery needs a title.');
        }
    }

    private static function assertKeyOf(DiscoveryType $type, string $key): void
    {
        $prefix = $type->value.self::KEY_SEPARATOR;

        if (self::namesSomethingAfter($key, $prefix)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf('Keys of %s discoveries are "%s" followed by what they name.', $type->value, $prefix));
    }

    private static function namesSomethingAfter(string $key, string $prefix): bool
    {
        return str_starts_with($key, $prefix) && mb_strlen($key) > mb_strlen($prefix);
    }

    private static function assertDatedWhenPicture(DiscoveryType $type, ?\DateTimeImmutable $date): void
    {
        if (DiscoveryType::Apod === $type) {
            self::assertDated($date);
        }
    }

    private static function assertDated(?\DateTimeImmutable $date): void
    {
        if (null === $date) {
            throw new \InvalidArgumentException('A picture of the day needs its date.');
        }
    }
}
