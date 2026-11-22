<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

final readonly class PersonReference
{
    private const string CATALOGUED_KEY = 'person:%s';
    private const string PAGE_KEY = 'person:page:%s';
    private const string UNLINKED_KEY = 'person:#%d:%s';

    private function __construct(
        public string $key,
    ) {
    }

    public static function catalogued(string $id): self
    {
        return new self(\sprintf(self::CATALOGUED_KEY, $id));
    }

    public static function page(string $title): self
    {
        return new self(\sprintf(self::PAGE_KEY, $title));
    }

    public static function unlinked(int $year, string $nameHash): self
    {
        return new self(\sprintf(self::UNLINKED_KEY, $year, $nameHash));
    }
}
