<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Metrics\HttpMetricsListener;
use App\Shared\Metrics\Metric;
use App\Tests\Support\Metrics\RecordingMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpMetricsListenerTest extends TestCase
{
    private const string STARTED_AT = '1795260000.250';
    private const string NOW = '@1795260000.750';

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('requests')]
    public function testCountsARequestWithBoundedLabels(string $method, ?string $route, int $code, array $expected): void
    {
        // Given
        $recorded = new RecordingMetrics();
        $request = Request::create('/api/briefings/2024-03-15', $method, server: ['REQUEST_TIME_FLOAT' => self::STARTED_AT]);
        $request->attributes->set('_route', $route);

        // When
        new HttpMetricsListener($recorded, new MockClock(self::NOW))->record(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response('', $code)));

        // Then
        self::assertSame([[Metric::HttpRequests, $expected]], $recorded->increments);
    }

    /**
     * @return iterable<string, array{string, ?string, int, array<string, string>}>
     */
    public static function requests(): iterable
    {
        yield 'a known code' => [Request::METHOD_GET, 'briefing_show', 502, ['route' => 'briefing_show', 'method' => 'GET', 'code' => '502']];
        yield 'HEAD keeps its method' => [Request::METHOD_HEAD, 'shared_health', 200, ['route' => 'shared_health', 'method' => 'HEAD', 'code' => '200']];
        yield 'an unlisted server error by its class' => [Request::METHOD_GET, 'briefing_show', 504, ['route' => 'briefing_show', 'method' => 'GET', 'code' => '5xx']];
        yield 'an unlisted client error by its class' => [Request::METHOD_GET, 'briefing_show', 418, ['route' => 'briefing_show', 'method' => 'GET', 'code' => '4xx']];
        yield 'an unusual method' => ['PROPFIND', null, 405, ['route' => 'unmatched', 'method' => 'other', 'code' => '405']];
    }

    public function testTimesTheRequestFromItsStart(): void
    {
        // Given
        $recorded = new RecordingMetrics();
        $request = Request::create('/api/health', server: ['REQUEST_TIME_FLOAT' => self::STARTED_AT]);
        $request->attributes->set('_route', 'shared_health');

        // When
        new HttpMetricsListener($recorded, new MockClock(self::NOW))->record(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response()));

        // Then
        self::assertSame([[Metric::HttpRequestDuration, 0.5, ['route' => 'shared_health']]], $recorded->observations);
    }
}
