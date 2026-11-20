<?php

declare(strict_types=1);

namespace App\Shared\RequestId;

use Symfony\Contracts\Service\ResetInterface;

final class RequestIdHolder implements ResetInterface
{
    private ?string $requestId = null;

    public function get(): ?string
    {
        return $this->requestId;
    }

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function reset(): void
    {
        $this->requestId = null;
    }
}
