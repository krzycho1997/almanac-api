<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

final class FailingFlush
{
    public function onFlush(): void
    {
        throw new \RuntimeException('The flush failed.');
    }
}
