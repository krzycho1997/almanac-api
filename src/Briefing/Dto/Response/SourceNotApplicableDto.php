<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class SourceNotApplicableDto implements SourceStatusDto
{
    public SourceStatusValue $status;

    public function __construct()
    {
        $this->status = SourceStatusValue::NotApplicable;
    }
}
