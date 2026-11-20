<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

final readonly class UpstreamFixture
{
    public function __construct(
        public string $path,
        public string $url,
        public int $expectedStatus,
        public Trim $trim,
        public Recording $recording,
        public Comparison $comparison,
        public ?ClientCall $call,
    ) {
    }

    public function isRepeatable(): bool
    {
        return Recording::Repeatable === $this->recording;
    }

    public function isComparedExactly(): bool
    {
        return Comparison::Exact === $this->comparison;
    }

    public function host(): string
    {
        return (string) parse_url($this->url, \PHP_URL_HOST);
    }
}
