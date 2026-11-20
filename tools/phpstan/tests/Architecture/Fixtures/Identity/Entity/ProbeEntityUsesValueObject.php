<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Identity\Contract\ProbeUserId;
use App\Identity\ProbeInternal;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProbeEntityUsesValueObject
{
    public function __construct(
        public ProbeUserId $id,
        public ProbeInternal $value,
    ) {
    }
}
