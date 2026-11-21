<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Discovery;

use App\Briefing\Discovery\PersonReference;
use PHPUnit\Framework\TestCase;

final class PersonReferenceTest extends TestCase
{
    public function testKeepsTheStoredKeyFormatsOfPeople(): void
    {
        // When
        $keys = [
            PersonReference::catalogued('Q1048')->key,
            PersonReference::page('Julius Caesar')->key,
            PersonReference::unlinked(1900, 'b29f8c6a53ae6e59')->key,
        ];

        // Then
        self::assertSame(['person:Q1048', 'person:page:Julius Caesar', 'person:#1900:b29f8c6a53ae6e59'], $keys);
    }
}
