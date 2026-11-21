<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Request;

use App\Briefing\Contract\DiscoveryId;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateBookmarkRequest
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('string'),
            new Assert\Uuid(versions: [Assert\Uuid::V5_SHA1]),
        ])]
        public mixed $discoveryId = null,
    ) {
    }

    public function discoveryId(): DiscoveryId
    {
        if (\is_string($this->discoveryId)) {
            return DiscoveryId::fromString($this->discoveryId);
        }

        throw new \LogicException('The discovery id is read only after validation.');
    }
}
