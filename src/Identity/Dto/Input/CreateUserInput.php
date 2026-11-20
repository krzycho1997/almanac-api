<?php

declare(strict_types=1);

namespace App\Identity\Dto\Input;

use App\Identity\Email;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class CreateUserInput
{
    private const string DEFAULT_TTL = '30d';
    private const string TTL_FORMAT = '/^(?<amount>[1-9][0-9]{0,5})(?<unit>[hd])$/D';
    private const string TTL_FORMAT_MESSAGE = 'Give the lifetime in hours (12h) or days (30d).';
    private const string TTL_RANGE_MESSAGE = 'The lifetime must be between 1h and 365d.';
    private const string HOURS = 'h';
    private const string DAYS = 'd';
    private const int HOURS_PER_DAY = 24;
    private const int MAX_TTL_HOURS = 365 * self::HOURS_PER_DAY;
    private const string TTL = 'ttl';
    private const string HOURS_INTERVAL = 'PT%dH';

    #[Argument(description: 'Address of the user; an existing user gets a new token and loses the old ones')]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5)]
    #[Assert\Length(max: Email::MAX_LENGTH)]
    public string $email;

    #[Option(description: 'Lifetime of the token in hours (12h) or days (30d), from 1h to 365d')]
    #[Assert\NotBlank(message: self::TTL_FORMAT_MESSAGE)]
    #[Assert\Regex(self::TTL_FORMAT, message: self::TTL_FORMAT_MESSAGE)]
    public string $ttl = self::DEFAULT_TTL;

    #[Assert\Callback]
    public function validateTtlRange(ExecutionContextInterface $context): void
    {
        $hours = self::hoursIn($this->ttl);

        if (null === $hours) {
            return;
        }

        if ($hours > self::MAX_TTL_HOURS) {
            $context->buildViolation(self::TTL_RANGE_MESSAGE)->atPath(self::TTL)->addViolation();
        }
    }

    public function toEmail(): Email
    {
        return Email::fromString($this->email);
    }

    public function toTtl(): \DateInterval
    {
        $hours = self::hoursIn($this->ttl);

        if (\is_int($hours)) {
            return new \DateInterval(\sprintf(self::HOURS_INTERVAL, $hours));
        }

        throw new \LogicException('The lifetime is read only after the input passed validation.');
    }

    private static function hoursIn(string $ttl): ?int
    {
        if (1 === preg_match(self::TTL_FORMAT, $ttl, $match)) {
            return (int) $match['amount'] * self::hoursPer($match['unit']);
        }

        return null;
    }

    private static function hoursPer(string $unit): int
    {
        return match ($unit) {
            self::HOURS => 1,
            self::DAYS => self::HOURS_PER_DAY,
            default => throw new \LogicException('The lifetime format allows only hours and days.'),
        };
    }
}
