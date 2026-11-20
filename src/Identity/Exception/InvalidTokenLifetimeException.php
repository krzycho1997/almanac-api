<?php

declare(strict_types=1);

namespace App\Identity\Exception;

final class InvalidTokenLifetimeException extends \InvalidArgumentException
{
    public static function notPositive(): self
    {
        return new self('An access token must live for a positive amount of time.');
    }
}
