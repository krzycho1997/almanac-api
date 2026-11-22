<?php

declare(strict_types=1);

namespace App\Briefing\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(self::TRANSPORT)]
final readonly class PrefetchDateMessage
{
    public const string TRANSPORT = 'async';

    public function __construct(
        public string $date,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }
}
