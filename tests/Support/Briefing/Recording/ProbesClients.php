<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

use App\Briefing\Source\Apod\ApodSource;
use App\Briefing\Source\OnThisDay\OnThisDaySource;
use App\Briefing\Source\Weather\WeatherSource;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait ProbesClients
{
    use InteractsWithTestServices;

    private static function probe(): ClientProbe
    {
        return new ClientProbe(
            self::testService(UpstreamMockResponseFactory::class),
            self::testService(ApodSource::class),
            self::testService(OnThisDaySource::class),
            self::testService(WeatherSource::class),
            new FixtureFiles(),
        );
    }
}
