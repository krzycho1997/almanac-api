<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class FunctionalTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    protected const string NOW = '2026-11-18 12:00:00';
    protected const string TIMEZONE = 'UTC';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone(self::TIMEZONE)));
        $this->client = self::createClient();
    }
}
