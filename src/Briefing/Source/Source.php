<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\SourceName;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface Source
{
    public function name(): SourceName;

    public function client(): SourceClient;
}
