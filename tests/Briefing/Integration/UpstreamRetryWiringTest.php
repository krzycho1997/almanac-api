<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Tests\Support\InspectedContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpstreamRetryWiringTest extends TestCase
{
    private const string RETRY_DELAY = 'app.briefing.retry_delay_ms';

    #[DataProvider('environments')]
    public function testWaitsAQuarterOfASecondBeforeTheRetry(string $environment): void
    {
        // When
        $delay = InspectedContainer::of($environment)->getParameter(self::RETRY_DELAY);

        // Then
        self::assertSame(250, $delay);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }
}
