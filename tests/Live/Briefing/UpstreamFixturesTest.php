<?php

declare(strict_types=1);

namespace App\Tests\Live\Briefing;

use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\Recording\FixtureFiles;
use App\Tests\Support\Briefing\Recording\ProbesClients;
use App\Tests\Support\Briefing\Recording\ShortRetryAfter;
use App\Tests\Support\Briefing\Recording\UpstreamFixture;
use App\Tests\Support\Briefing\Recording\UpstreamFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class UpstreamFixturesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ProbesClients;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string RECORD = 'RECORD_FIXTURES';
    private const string ENABLED = '1';
    private const string USER_AGENT = 'ALMANAC_USER_AGENT';
    private const int PAUSE_MICROSECONDS = 1_000_000;
    private const float TIMEOUT_SECONDS = 10.0;
    private const int RETRIES = 1;
    private const string RETRY_AFTER = 'retry-after';

    #[DataProvider('repeatableFixtures')]
    public function testOurClientsStillAcceptWhatTheUpstreamAnswers(UpstreamFixture $fixture): void
    {
        // Given
        self::mockTime(self::NOW);
        usleep(self::PAUSE_MICROSECONDS);

        // When
        $response = self::fetch($fixture);

        // Then
        self::assertSame($fixture->expectedStatus, $response->getStatusCode(), self::unexpectedStatus($response));
        $body = $fixture->trim->apply(json_decode($response->getContent(false), false, flags: \JSON_THROW_ON_ERROR));
        $result = self::probe()->resolve($fixture, FixtureFiles::encode($body));
        self::assertSame(SourceStatus::Ok, $result->status, self::rejected($fixture, $result));

        // When
        self::recordWhenAsked($fixture, $body, $response);

        // Then
        self::assertNoDrift($fixture, $body);
    }

    /**
     * @return iterable<string, array{UpstreamFixture}>
     */
    public static function repeatableFixtures(): iterable
    {
        foreach (UpstreamFixtures::repeatable() as $fixture) {
            yield $fixture->path => [$fixture];
        }
    }

    private static function fetch(UpstreamFixture $fixture): ResponseInterface
    {
        $client = new RetryableHttpClient(HttpClient::create(['headers' => ['User-Agent' => self::setting(self::USER_AGENT)], 'max_redirects' => 0, 'timeout' => self::TIMEOUT_SECONDS]), new ShortRetryAfter(), self::RETRIES);

        return $client->request(Request::METHOD_GET, $fixture->url);
    }

    private static function recordWhenAsked(UpstreamFixture $fixture, mixed $body, ResponseInterface $response): void
    {
        if (self::ENABLED === self::setting(self::RECORD)) {
            new FixtureFiles()->record($fixture, $body, $response->getStatusCode(), $response->getHeaders(false));
        }
    }

    private static function assertNoDrift(UpstreamFixture $fixture, mixed $body): void
    {
        if ($fixture->isComparedExactly()) {
            self::assertSame(FixtureFiles::canonical(new FixtureFiles()->body($fixture)), FixtureFiles::canonical($body), "{$fixture->path} drifted from {$fixture->url}; re-record it with ".self::RECORD.'=1');
        }
    }

    private static function setting(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        if (\is_string($value)) {
            return $value;
        }

        return '';
    }

    private static function unexpectedStatus(ResponseInterface $response): string
    {
        return 'The upstream answered '.$response->getStatusCode().'; retry after: '.($response->getHeaders(false)[self::RETRY_AFTER][0] ?? 'not given').' s. Nothing was recorded.';
    }

    private static function rejected(UpstreamFixture $fixture, SourceResult $result): string
    {
        return "Our client rejects the live answer for {$fixture->path} ({$result->failureKind?->value}, {$result->cause?->errorType}). Nothing was recorded.";
    }
}
