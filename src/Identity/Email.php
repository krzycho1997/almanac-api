<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Exception\InvalidEmailException;

final readonly class Email
{
    public const int MAX_LENGTH = 254;
    private const string SHAPE = '/^[^@\s\p{Z}\p{Cc}\p{Cf}]+@[^@\s\p{Z}\p{Cc}\p{Cf}]+$/uD';
    private const string ENCODING = 'UTF-8';
    private const string SURROUNDING_WHITESPACE = " \t\n\r";

    /**
     * @param non-empty-lowercase-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $email): self
    {
        if (mb_check_encoding($email, self::ENCODING)) {
            return self::normalized(mb_strtolower(trim($email, self::SURROUNDING_WHITESPACE)));
        }

        throw InvalidEmailException::malformed();
    }

    private static function normalized(string $email): self
    {
        if (self::isWellFormed($email)) {
            return new self($email);
        }

        throw InvalidEmailException::malformed();
    }

    /**
     * @phpstan-assert-if-true non-empty-lowercase-string $email
     */
    private static function isWellFormed(string $email): bool
    {
        if (mb_strlen($email) > self::MAX_LENGTH) {
            return false;
        }

        return 1 === preg_match(self::SHAPE, $email);
    }
}
