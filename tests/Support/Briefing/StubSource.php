<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Location;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class StubSource implements Source, SourceClient
{
    /**
     * @param \Closure(): PendingFetch $request
     */
    public function __construct(
        private SourceName $name,
        private \Closure $request,
        private bool $covers = true,
    ) {
    }

    public function name(): SourceName
    {
        return $this->name;
    }

    public function client(): SourceClient
    {
        return $this;
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        return $this->covers;
    }

    public function request(\DateTimeImmutable $date, Location $location): PendingFetch
    {
        return ($this->request)();
    }
}
