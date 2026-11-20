<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithLogs
{
    protected static function logHandler(): TestHandler
    {
        foreach (self::getContainer()->get('logger')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler;
            }
        }

        self::fail('The test environment logs to no Monolog TestHandler.');
    }
}
