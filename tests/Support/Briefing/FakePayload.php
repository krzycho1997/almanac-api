<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\SourcePayload;

final readonly class FakePayload implements SourcePayload
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public array $data,
    ) {
    }
}
