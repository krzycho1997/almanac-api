<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\Source\UpstreamRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpFoundation\Request;

final class UpstreamRetryStrategyTest extends TestCase
{
    private const int DELAY_MS = 250;
    private const string URL = 'https://upstream.test/resource';

    public function testWaitsAsLongAsTheDecoratedStrategy(): void
    {
        // Given
        $strategy = new UpstreamRetryStrategy(new GenericRetryStrategy(delayMs: self::DELAY_MS, jitter: 0.0));

        // When
        $delay = $strategy->getDelay(self::firstAttempt(), null, null);

        // Then
        self::assertSame(self::DELAY_MS, $delay);
    }

    private static function firstAttempt(): AsyncContext
    {
        $passthru = null;
        $client = new MockHttpClient();
        $response = $client->request(Request::METHOD_GET, self::URL);
        $info = ['retry_count' => 0];

        return new AsyncContext($passthru, $client, $response, $info, null, 0);
    }
}
