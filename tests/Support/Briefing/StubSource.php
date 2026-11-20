<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class StubSource implements Source, SourceClient
{
    use KeepsFinalSnapshotsByDate {
        scopeFor as private datedScopeFor;
    }

    /**
     * @param \Closure(): PendingFetch $request
     */
    public function __construct(
        private SourceName $name,
        private \Closure $request,
        private bool $covers = true,
        private ?\DateInterval $refreshAfter = null,
        private ?SnapshotScope $scope = null,
    ) {
    }

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope
    {
        return $this->scope ?? $this->datedScopeFor($date, $location);
    }

    public function refreshAfter(): ?\DateInterval
    {
        return $this->refreshAfter;
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
