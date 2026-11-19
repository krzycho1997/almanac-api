<?php

declare(strict_types=1);

namespace App\Identity\Contract;

use Doctrine\DBAL\Types\GuidType;

final class ProbeUserIdType extends GuidType
{
}
