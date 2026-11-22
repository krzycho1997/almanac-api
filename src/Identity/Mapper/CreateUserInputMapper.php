<?php

declare(strict_types=1);

namespace App\Identity\Mapper;

use App\Identity\Dto\Input\CreateUserInput;
use App\Identity\Email;
use App\Identity\TokenLifetime;

final readonly class CreateUserInputMapper
{
    public function convertToEmail(CreateUserInput $input): Email
    {
        return Email::fromString($input->email);
    }

    public function convertToTtl(CreateUserInput $input): \DateInterval
    {
        return TokenLifetime::parse($input->ttl)?->interval()
            ?? throw new \LogicException('The lifetime is read only after the input passed validation.');
    }
}
