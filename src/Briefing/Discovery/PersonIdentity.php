<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\Model\NotablePerson;

interface PersonIdentity
{
    /**
     * @return list<PersonReference>
     */
    public function referencesOf(NotablePerson $person): array;
}
