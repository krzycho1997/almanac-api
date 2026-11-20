<?php

declare(strict_types=1);

namespace App\Identity\Exception;

use App\Identity\Email;

final class InvalidEmailException extends \InvalidArgumentException
{
    public static function malformed(): self
    {
        return new self(\sprintf('An email address needs one "@" with text on both sides and at most %d characters.', Email::MAX_LENGTH));
    }
}
