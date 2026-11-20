<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\Exception\PayloadDefect;
use App\Briefing\FailureCause;
use App\Briefing\FailureKind;
use App\Briefing\SourceFailureLog;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\FakePayload;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceFailureLogTest extends TestCase
{
    private const string DATE = '2026-11-05';
    private const string MESSAGE = 'Source {source} fetch failed: {kind}';
    private const int DURATION_MS = 1234;

    private TestHandler $handler;
    private SourceFailureLog $log;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->log = new SourceFailureLog(new Logger('briefing', [$this->handler]));
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('failures')]
    public function testLogsAFailureAtItsKindsLevel(SourceResult $failure, Level $level, array $context): void
    {
        // When
        $this->log->record($failure, new \DateTimeImmutable(self::DATE), self::DURATION_MS);

        // Then
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame($level, $records[0]->level);
        self::assertSame(self::MESSAGE, $records[0]->message);
        self::assertSame($context, $records[0]->context);
    }

    /**
     * @return iterable<string, array{SourceResult, Level, array<string, mixed>}>
     */
    public static function failures(): iterable
    {
        $retryAt = new \DateTimeImmutable('2026-11-20T13:00:00Z');

        yield 'server error' => [
            SourceResult::failed(SourceName::Weather, FailureKind::Unavailable, 503),
            Level::Warning,
            ['source' => 'weather', 'date' => self::DATE, 'kind' => 'unavailable', 'httpStatus' => 503, 'durationMs' => 1234],
        ];
        yield 'timeout' => [
            SourceResult::failed(SourceName::Weather, FailureKind::Unavailable, cause: new FailureCause('TimeoutException')),
            Level::Warning,
            ['source' => 'weather', 'date' => self::DATE, 'kind' => 'unavailable', 'errorType' => 'TimeoutException', 'durationMs' => 1234],
        ];
        yield 'rate limit' => [
            SourceResult::failed(SourceName::OnThisDay, FailureKind::RateLimited, 429, $retryAt),
            Level::Warning,
            ['source' => 'on_this_day', 'date' => self::DATE, 'kind' => 'rate_limited', 'httpStatus' => 429, 'retryAt' => $retryAt, 'durationMs' => 1234],
        ];
        yield 'rejected request' => [
            SourceResult::failed(SourceName::Apod, FailureKind::Rejected, 400),
            Level::Notice,
            ['source' => 'apod', 'date' => self::DATE, 'kind' => 'rejected', 'httpStatus' => 400, 'durationMs' => 1234],
        ];
        yield 'refused key' => [
            SourceResult::failed(SourceName::Apod, FailureKind::Misconfigured, 403),
            Level::Error,
            ['source' => 'apod', 'date' => self::DATE, 'kind' => 'misconfigured', 'httpStatus' => 403, 'durationMs' => 1234],
        ];
        yield 'invalid payload' => [
            SourceResult::failed(SourceName::Apod, FailureKind::InvalidPayload, 200, cause: new FailureCause('InvalidUpstreamPayloadException', ['date', 'url'], PayloadDefect::InvalidValue)),
            Level::Error,
            ['source' => 'apod', 'date' => self::DATE, 'kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date', 'url'], 'reason' => 'invalid_value', 'durationMs' => 1234],
        ];
        yield 'NASA placeholder' => [
            SourceResult::failed(SourceName::Apod, FailureKind::InvalidPayload, 200, cause: new FailureCause('InvalidUpstreamPayloadException', ['title'], PayloadDefect::Placeholder)),
            Level::Error,
            ['source' => 'apod', 'date' => self::DATE, 'kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['title'], 'reason' => 'placeholder', 'durationMs' => 1234],
        ];
        yield 'body that is not JSON' => [
            SourceResult::failed(SourceName::Apod, FailureKind::InvalidPayload, 200, cause: new FailureCause('InvalidUpstreamPayloadException', [], PayloadDefect::NotJson)),
            Level::Error,
            ['source' => 'apod', 'date' => self::DATE, 'kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => [], 'reason' => 'not_json', 'durationMs' => 1234],
        ];
    }

    #[DataProvider('answers')]
    public function testLogsNothingForASourceThatDidNotFail(SourceResult $answer): void
    {
        // When
        $this->log->record($answer, new \DateTimeImmutable(self::DATE), self::DURATION_MS);

        // Then
        self::assertSame([], $this->handler->getRecords());
    }

    /**
     * @return iterable<string, array{SourceResult}>
     */
    public static function answers(): iterable
    {
        yield 'data' => [SourceResult::ok(SourceName::Apod, new FakePayload([]))];
        yield 'not applicable' => [SourceResult::notApplicable(SourceName::Apod)];
    }
}
