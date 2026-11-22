<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Request;

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
}
