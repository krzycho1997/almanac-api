<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\AbstractUid;

/**
 * @template T of AbstractUid
 */
abstract class UidIdType extends AbstractUidType
{
    private const array SCALAR_TYPES = ['null', 'string'];

    /**
     * @return T|null
     */
    final public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AbstractUid
    {
        $id = parent::convertToPHPValue(self::asString($value), $platform);
        $class = $this->getUidClass();

        if ($id instanceof $class) {
            return $id;
        }

        return null;
    }

    final public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($this->isAnotherUid($value)) {
            throw InvalidType::new($value, $this->getName(), [...self::SCALAR_TYPES, $this->getUidClass()]);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    abstract public function getName(): string;

    /**
     * @return class-string<T>
     */
    abstract protected function getUidClass(): string;

    private static function asString(mixed $value): mixed
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        return $value;
    }

    private function isAnotherUid(mixed $value): bool
    {
        $class = $this->getUidClass();

        if ($value instanceof $class) {
            return false;
        }

        return $value instanceof AbstractUid;
    }
}
