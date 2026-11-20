<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\ValueResolver\BriefingDateValueResolver;
use App\Tests\Support\InspectedContainer;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BriefingDateWiringTest extends KernelTestCase
{
    private const string TARGETED_RESOLVER = 'controller.targeted_value_resolver';

    private const string APP_TIMEZONE = 'APP_TIMEZONE';
    private const string FAR_EAST = 'Pacific/Kiritimati';

    /**
     * @var array{server: mixed, env: mixed}|null
     */
    private ?array $previousTimezone = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if (null === $this->previousTimezone) {
            return;
        }

        $_SERVER[self::APP_TIMEZONE] = $this->previousTimezone['server'];
        $_ENV[self::APP_TIMEZONE] = $this->previousTimezone['env'];
    }

    public function testTellsTimeInTheApplicationTimeZone(): void
    {
        // Given
        $this->previousTimezone = ['server' => $_SERVER[self::APP_TIMEZONE] ?? null, 'env' => $_ENV[self::APP_TIMEZONE] ?? null];
        $_SERVER[self::APP_TIMEZONE] = $_ENV[self::APP_TIMEZONE] = self::FAR_EAST;

        // When
        $now = self::getContainer()->get(ClockInterface::class)->now();

        // Then
        self::assertSame(self::FAR_EAST, $now->getTimezone()->getName());
    }

    public function testRegistersTheDateResolverOnlyForArgumentsThatTargetIt(): void
    {
        // When
        $definition = InspectedContainer::of('test')->getDefinition(BriefingDateValueResolver::class);

        // Then
        self::assertTrue($definition->hasTag(self::TARGETED_RESOLVER));
    }
}
