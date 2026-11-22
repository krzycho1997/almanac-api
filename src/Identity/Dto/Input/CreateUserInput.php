<?php

declare(strict_types=1);

namespace App\Identity\Dto\Input;

use App\Identity\Dto\Input\Validator\ValidTokenLifetime;
use App\Identity\Email;
use App\Identity\TokenLifetime;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

#[ValidTokenLifetime]
final class CreateUserInput
{
    private const string DEFAULT_TTL = '30d';
    private const string TTL_FORMAT_MESSAGE = 'Give the lifetime in hours (12h) or days (30d).';

    #[Argument(description: 'Address of the user; an existing user gets a new token and loses the old ones')]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5)]
    #[Assert\Length(max: Email::MAX_LENGTH)]
    public string $email;

    #[Option(description: 'Lifetime of the token in hours (12h) or days (30d), from 1h to 365d')]
    #[Assert\NotBlank(message: self::TTL_FORMAT_MESSAGE)]
    #[Assert\Regex(TokenLifetime::FORMAT, message: self::TTL_FORMAT_MESSAGE)]
    public string $ttl = self::DEFAULT_TTL;

    public function toEmail(): Email
    {
        return Email::fromString($this->email);
    }

    public function toTtl(): \DateInterval
    {
        return TokenLifetime::parse($this->ttl)?->interval()
            ?? throw new \LogicException('The lifetime is read only after the input passed validation.');
    }
}
