<?php

declare(strict_types=1);

namespace App\Bookmark\Controller;

use Symfony\Bridge\Doctrine\Types\UuidType;

final class ProbeControllerUsesDoctrineBridge
{
    public function run(UuidType $type): void
    {
    }
}
