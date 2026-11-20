<?php

declare(strict_types=1);

namespace App\Identity\Doctrine;

use App\Identity\Email;
use App\Identity\Exception\InvalidEmailException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

final class EmailType extends StringType
{
    public const string NAME = 'email';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof Email) {
            return $value->value;
        }

        if (null === $value) {
            return null;
        }

        throw InvalidType::new($value, self::NAME, ['null', Email::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Email
    {
        if (\is_string($value)) {
            return self::emailFrom($value);
        }

        if (null === $value) {
            return null;
        }

        throw ValueNotConvertible::new($value, self::NAME);
    }

    private static function emailFrom(string $value): Email
    {
        try {
            return Email::fromString($value);
        } catch (InvalidEmailException $exception) {
            throw ValueNotConvertible::new($value, self::NAME, null, $exception);
        }
    }
}
