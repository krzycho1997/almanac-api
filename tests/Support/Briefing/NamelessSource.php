<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class NamelessSource implements Source
{
    public function name(): SourceName
    {
        return SourceName::Apod;
    }

    public function client(): SourceClient
    {
        throw new \LogicException('A source without a declared name exists only to be rejected.');
    }
}
