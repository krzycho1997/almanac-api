<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

final class CallJournal
{
    /**
     * @var list<string>
     */
    private array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
