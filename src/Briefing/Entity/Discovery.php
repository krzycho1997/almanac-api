<?php

declare(strict_types=1);

namespace App\Briefing\Entity;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryIdType;
use App\Briefing\Contract\DiscoveryType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'briefing_discovery')]
#[ORM\UniqueConstraint(name: 'briefing_discovery_key_unique', columns: ['key'])]
class Discovery
{
    public const int LONGEST_KEY = 300;
    public const string SUMMARY = 'summary';
    public const string URL = 'url';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        #[ORM\Id, ORM\Column(type: DiscoveryIdType::NAME)]
        public private(set) DiscoveryId $id,
        #[ORM\Column(type: Types::STRING, length: self::LONGEST_KEY)]
        public private(set) string $key,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: DiscoveryType::class)]
        public private(set) DiscoveryType $type,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        public private(set) ?\DateTimeImmutable $date,
        #[ORM\Column(type: Types::TEXT)]
        public private(set) string $title,
        #[ORM\Column(type: Types::JSONB)]
        public private(set) array $data,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) \DateTimeImmutable $createdAt,
    ) {
    }
}
