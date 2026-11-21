<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface HealthCheck
{
    public function name(): string;

    /**
     * @return array<string, string>
     */
    public function report(): array;
}
