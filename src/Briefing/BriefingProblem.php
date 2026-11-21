<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class BriefingProblem
{
    public function __construct(
        public SourceName $source,
        public SourceFailure $failure,
        public ProblemTiming $timing,
    ) {
    }
}
