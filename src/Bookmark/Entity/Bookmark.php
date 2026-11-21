<?php

declare(strict_types=1);

namespace App\Bookmark\Entity;

use App\Bookmark\BookmarkId;
use App\Bookmark\Doctrine\BookmarkIdType;
use App\Bookmark\Repository\BookmarkRepository;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryIdType;
use App\Identity\Contract\UserId;
use App\Identity\Contract\UserIdType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookmarkRepository::class, readOnly: true)]
#[ORM\Table(name: 'bookmark')]
#[ORM\UniqueConstraint(name: 'bookmark_user_discovery_unique', columns: ['user_id', 'discovery_id'])]
#[ORM\Index(name: 'bookmark_user_created_idx', columns: ['user_id', 'created_at', 'id'])]
class Bookmark
{
    #[ORM\Id]
    #[ORM\Column(type: BookmarkIdType::NAME)]
    public private(set) BookmarkId $id;

    #[ORM\Column(type: UserIdType::NAME)]
    public private(set) UserId $userId;

    #[ORM\Column(type: DiscoveryIdType::NAME)]
    public private(set) DiscoveryId $discoveryId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(UserId $userId, DiscoveryId $discoveryId, \DateTimeImmutable $createdAt)
    {
        $this->id = new BookmarkId();
        $this->userId = $userId;
        $this->discoveryId = $discoveryId;
        $this->createdAt = $createdAt->setMicrosecond(0);
    }
}
