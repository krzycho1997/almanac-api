<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class AnotherApodSource implements Source
{
    use KeepsFinalSnapshotsByDate;

    public const SourceName NAME = SourceName::Apod;

    public function name(): SourceName
    {
        return self::NAME;
    }

    public function client(): SourceClient
    {
        throw new \LogicException('A second APOD definition exists only to be rejected.');
    }
}
