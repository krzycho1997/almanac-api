<?php

declare(strict_types=1);

namespace App\Identity\Dto\Input\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTokenLifetime extends Constraint
{
    public const string TOO_LONG_ERROR = '64515634-42be-497d-a9fb-2668f6fa7eae';

    protected const array ERROR_NAMES = [
        self::TOO_LONG_ERROR => 'TOO_LONG_ERROR',
    ];

    public string $message = 'The lifetime must be between 1h and 365d.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
