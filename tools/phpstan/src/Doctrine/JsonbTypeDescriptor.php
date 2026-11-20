<?php

declare(strict_types=1);

namespace App\PHPStan\Doctrine;

use Doctrine\DBAL\Types\JsonbType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Doctrine\Descriptors\DoctrineTypeDescriptor;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

final class JsonbTypeDescriptor implements DoctrineTypeDescriptor
{
    public function getType(): string
    {
        return JsonbType::class;
    }

    public function getWritableToPropertyType(): Type
    {
        return self::jsonObject();
    }

    public function getWritableToDatabaseType(): Type
    {
        return self::jsonObject();
    }

    public function getDatabaseInternalType(): Type
    {
        return new StringType();
    }

    private static function jsonObject(): Type
    {
        return new ArrayType(new StringType(), new MixedType());
    }
}
