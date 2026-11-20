<?php

declare(strict_types=1);

namespace App\Briefing\Contract;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\AbstractUid;

final class DiscoveryIdType extends AbstractUidType
{
    public const string NAME = 'discovery_id';
    private const array STORED_TYPES = ['null', 'string', DiscoveryId::class];

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DiscoveryId
    {
        $id = parent::convertToPHPValue(self::asString($value), $platform);

        if ($id instanceof DiscoveryId) {
            return $id;
        }

        return null;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (self::isAnotherUid($value)) {
            throw InvalidType::new($value, self::NAME, self::STORED_TYPES);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    protected function getUidClass(): string
    {
        return DiscoveryId::class;
    }

    private static function asString(mixed $value): mixed
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        return $value;
    }

    private static function isAnotherUid(mixed $value): bool
    {
        if ($value instanceof DiscoveryId) {
            return false;
        }

        return $value instanceof AbstractUid;
    }
}
