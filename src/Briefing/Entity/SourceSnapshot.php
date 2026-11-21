<?php

declare(strict_types=1);

namespace App\Briefing\Entity;

use App\Briefing\FailureKind;
use App\Briefing\Repository\SourceSnapshotRepository;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use App\Briefing\SourceStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SourceSnapshotRepository::class, readOnly: true)]
#[ORM\Table(name: 'briefing_source_snapshot')]
class SourceSnapshot
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        #[ORM\Id, ORM\Column(type: Types::STRING, length: 16, enumType: SourceName::class)]
        public private(set) SourceName $source,
        #[ORM\Id, ORM\Column(type: Types::STRING, length: SnapshotScope::LONGEST_KEY)]
        public private(set) string $scopeKey,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        public private(set) ?\DateTimeImmutable $date,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: SourceStatus::class)]
        public private(set) SourceStatus $status,
        #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: FailureKind::class)]
        public private(set) ?FailureKind $error,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        public private(set) ?array $payload,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public private(set) ?\DateTimeImmutable $fetchedAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) \DateTimeImmutable $lastAttemptAt,
    ) {
    }
}
