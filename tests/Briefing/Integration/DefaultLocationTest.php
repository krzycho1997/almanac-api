<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Location;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DefaultLocationTest extends KernelTestCase
{
    public function testBuildsTheDefaultLocationFromTheEnvironment(): void
    {
        // When
        $location = self::getContainer()->get('app.briefing.default_location');

        // Then
        self::assertEquals(Location::defaultAt(48.86, 2.35, 'Paris'), $location);
    }
}
