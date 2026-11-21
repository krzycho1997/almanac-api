<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreaker;
use App\Briefing\CircuitBreaker\CircuitLog;
use App\Briefing\CircuitBreaker\CircuitPermit;
use App\Briefing\CircuitBreaker\CircuitState;
use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\CircuitBreaker\InMemoryCircuitBreakerStore;
use App\Tests\Support\Briefing\FakePayload;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CircuitBreakerTest extends TestCase
{
    private const string NOW = '2026-11-21 12:00:00 Europe/Warsaw';
    private const SourceName SOURCE = SourceName::Apod;
    private const string OK = 'ok';
    private const string RIGHT_AWAY = '+0 seconds';
    private const string CHANNEL = 'briefing';
    private const string OPENED = 'Circuit {source} opened for {openSeconds}s';
    private const string HALF_OPEN = 'Circuit {source} half-open, trial allowed';
    private const string CLOSED = 'Circuit {source} closed after its trial';

    private MockClock $clock;
    private InMemoryCircuitBreakerStore $store;
    private TestHandler $log;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->store = new InMemoryCircuitBreakerStore($this->clock);
        $this->log = new TestHandler();
        $this->breaker = new CircuitBreaker($this->store, $this->clock, new CircuitLog(new Logger(self::CHANNEL, [$this->log])));
    }

    /**
     * @param list<array{string, string, ?string}> $calls
     */
    #[DataProvider('calls')]
    public function testOpensAfterOutagesOfCalls(array $calls, ?string $expectedOpenUntil): void
    {
        // When
        foreach ($calls as [$at, $kind, $retryAt]) {
            $this->clock->modify(self::NOW.' '.$at);
            $this->breaker->record(self::resultOf($kind, self::moment($retryAt)), CircuitPermit::Call);
        }

        // Then
        self::assertEquals(self::moment($expectedOpenUntil), $this->store->openUntil(self::SOURCE->value));
    }

    /**
     * @return iterable<string, array{list<array{string, string, ?string}>, ?string}>
     */
    public static function calls(): iterable
    {
        $unavailable = FailureKind::Unavailable->value;
        $rateLimited = FailureKind::RateLimited->value;

        yield 'a success keeps it closed' => [[[self::RIGHT_AWAY, self::OK, null]], null];
        yield 'two outages keep it closed' => [self::outages(2, $unavailable), null];
        yield 'three outages in a row open it for a minute' => [self::outages(3, $unavailable), '+60 seconds'];
        yield 'a late outage of a call started before the opening does not move it' => [[...self::outages(3, $unavailable), ['+30 seconds', $unavailable, null]], '+60 seconds'];
        yield 'a success between outages starts the count again' => [[...self::outages(2, $unavailable), [self::RIGHT_AWAY, self::OK, null], ...self::outages(2, $unavailable)], null];
        yield 'a rejection between outages starts the count again' => [[...self::outages(2, $unavailable), [self::RIGHT_AWAY, FailureKind::Rejected->value, null], ...self::outages(2, $unavailable)], null];
        yield 'a 400 from APOD does not open it' => [self::outages(5, FailureKind::Rejected->value), null];
        yield 'invalid payloads do not open it' => [self::outages(3, FailureKind::InvalidPayload->value), null];
        yield 'a misconfigured key does not open it' => [self::outages(3, FailureKind::Misconfigured->value), null];
        yield 'outages forgotten after thirty minutes keep it closed' => [[...self::outages(2, $unavailable), ['+30 minutes', $unavailable, null]], null];
        yield 'outages just inside thirty minutes open it' => [[...self::outages(2, $unavailable), ['+29 minutes +59 seconds', $unavailable, null]], '+30 minutes +59 seconds'];
        yield 'outages ten minutes apart, one per refresh pause, open it' => [[[self::RIGHT_AWAY, $unavailable, null], ['+10 minutes', $unavailable, null], ['+20 minutes', $unavailable, null]], '+21 minutes'];
        yield 'a 429 opens it until Retry-After at once' => [[[self::RIGHT_AWAY, $rateLimited, '+2 hours']], '+2 hours'];
        yield 'a 429 without Retry-After opens it for 15 minutes' => [[[self::RIGHT_AWAY, $rateLimited, null]], '+15 minutes'];
        yield 'a 429 retrying sooner than a minute opens it for a minute' => [[[self::RIGHT_AWAY, $rateLimited, '+10 seconds']], '+60 seconds'];
        yield 'a 429 retrying in the past opens it for a minute' => [[[self::RIGHT_AWAY, $rateLimited, '-1 second']], '+60 seconds'];
        yield 'a 429 retrying in just over a minute opens it until then' => [[[self::RIGHT_AWAY, $rateLimited, '+61 seconds']], '+61 seconds'];
        yield 'a late 429 of a call does not shorten an opening' => [[[self::RIGHT_AWAY, $rateLimited, '+2 hours'], ['+30 seconds', $rateLimited, '+35 seconds']], '+2 hours'];
        yield 'a later 429 of a call lengthens an opening' => [[[self::RIGHT_AWAY, $rateLimited, '+2 hours'], ['+30 seconds', $rateLimited, '+3 hours']], '+3 hours'];
        yield 'two outages and a 429 open it until Retry-After' => [[...self::outages(2, $unavailable), [self::RIGHT_AWAY, $rateLimited, '+2 hours']], '+2 hours'];
    }

    #[DataProvider('permits')]
    public function testGrantsAPermitByTheStateOfTheCircuit(?string $openUntil, bool $trialTaken, CircuitState $expectedState, CircuitPermit $expectedPermit): void
    {
        // Given
        self::openUntil($this->store, self::moment($openUntil));
        $this->takeTrialIf($trialTaken);

        // When
        $state = $this->breaker->state(self::SOURCE);
        $permit = $this->breaker->tryAcquire(self::SOURCE);

        // Then
        self::assertSame($expectedState, $state);
        self::assertSame($expectedPermit, $permit);
    }

    /**
     * @return iterable<string, array{?string, bool, CircuitState, CircuitPermit}>
     */
    public static function permits(): iterable
    {
        yield 'closed' => [null, false, CircuitState::Closed, CircuitPermit::Call];
        yield 'open for one more second' => ['+1 second', false, CircuitState::Open, CircuitPermit::Refused];
        yield 'open until exactly now' => [self::RIGHT_AWAY, false, CircuitState::HalfOpen, CircuitPermit::Trial];
        yield 'half-open long after the opening' => ['-1 day', false, CircuitState::HalfOpen, CircuitPermit::Trial];
        yield 'half-open with the trial taken' => ['-1 minute', true, CircuitState::HalfOpen, CircuitPermit::Refused];
    }

    #[DataProvider('leases')]
    public function testLeasesTheTrialForThirtySeconds(string $later, CircuitPermit $expected): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));
        $first = $this->breaker->tryAcquire(self::SOURCE);
        $this->clock->modify(self::NOW.' '.$later);

        // When
        $permit = $this->breaker->tryAcquire(self::SOURCE);

        // Then
        self::assertSame(CircuitPermit::Trial, $first);
        self::assertSame($expected, $permit);
    }

    /**
     * @return iterable<string, array{string, CircuitPermit}>
     */
    public static function leases(): iterable
    {
        yield 'just under the lease' => ['+29 seconds', CircuitPermit::Refused];
        yield 'exactly the lease' => ['+30 seconds', CircuitPermit::Trial];
    }

    #[DataProvider('trials')]
    public function testClosesOrReopensAfterATrial(string $kind, ?string $retryAt, ?string $expectedOpenUntil): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));
        $permit = $this->breaker->tryAcquire(self::SOURCE);

        // When
        $this->breaker->record(self::resultOf($kind, self::moment($retryAt)), $permit);

        // Then
        self::assertSame(CircuitPermit::Trial, $permit);
        self::assertEquals(self::moment($expectedOpenUntil), $this->store->openUntil(self::SOURCE->value));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function trials(): iterable
    {
        yield 'a success closes it' => [self::OK, null, null];
        yield 'a rejection closes it, the upstream answered' => [FailureKind::Rejected->value, null, null];
        yield 'an invalid payload closes it' => [FailureKind::InvalidPayload->value, null, null];
        yield 'a misconfigured key closes it' => [FailureKind::Misconfigured->value, null, null];
        yield 'an outage reopens it for a minute' => [FailureKind::Unavailable->value, null, '+60 seconds'];
        yield 'a 429 reopens it until Retry-After' => [FailureKind::RateLimited->value, '+2 hours', '+2 hours'];
        yield 'a 429 without Retry-After reopens it for 15 minutes' => [FailureKind::RateLimited->value, null, '+15 minutes'];
    }

    public function testForgetsEarlierOutagesWhenATrialCloses(): void
    {
        // Given
        $this->recordCalls(3, FailureKind::Unavailable);
        $this->clock->modify(self::NOW.' +61 seconds');
        $this->breaker->record(self::resultOf(self::OK, null), $this->breaker->tryAcquire(self::SOURCE));

        // When
        $this->recordCalls(2, FailureKind::Unavailable);

        // Then
        self::assertSame(CircuitState::Closed, $this->breaker->state(self::SOURCE));
    }

    public function testFreesTheTrialWhenItReopens(): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));
        $this->breaker->record(self::resultOf(FailureKind::Unavailable->value, null), $this->breaker->tryAcquire(self::SOURCE));
        $this->clock->modify(self::NOW.' +60 seconds');

        // When
        $permit = $this->breaker->tryAcquire(self::SOURCE);

        // Then
        self::assertSame(CircuitPermit::Trial, $permit);
    }

    #[DataProvider('refusedResults')]
    public function testLeavesTheCircuitAloneAfterARefusal(string $kind): void
    {
        // Given
        self::openUntil($this->store, self::moment('+30 seconds'));

        // When
        $this->breaker->record(self::resultOf($kind, null), CircuitPermit::Refused);

        // Then
        self::assertEquals(self::moment('+30 seconds'), $this->store->openUntil(self::SOURCE->value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedResults(): iterable
    {
        yield 'success' => [self::OK];
        yield 'rate limited' => [FailureKind::RateLimited->value];
        yield 'outage' => [FailureKind::Unavailable->value];
    }

    public function testOpensWhenTheCountHasPassedTheThresholdWithoutAnOpening(): void
    {
        // Given
        foreach (range(1, 3) as $ignored) {
            $this->store->recordFailure(self::SOURCE->value, $this->clock->now()->modify('+30 minutes'));
        }

        // When
        $this->breaker->record(self::resultOf(FailureKind::Unavailable->value, null), CircuitPermit::Call);

        // Then
        self::assertSame(CircuitState::Open, $this->breaker->state(self::SOURCE));
    }

    public function testLeavesAnOpeningAloneAfterASuccessfulCall(): void
    {
        // Given
        self::openUntil($this->store, self::moment('+30 seconds'));

        // When
        $this->breaker->record(self::resultOf(self::OK, null), CircuitPermit::Call);

        // Then
        self::assertEquals(self::moment('+30 seconds'), $this->store->openUntil(self::SOURCE->value));
    }

    public function testStaysHalfOpenAfterA429UntilATrial(): void
    {
        // Given
        $this->breaker->record(self::resultOf(FailureKind::RateLimited->value, self::moment('+1 hour')), CircuitPermit::Call);
        $this->clock->modify(self::NOW.' +1 day');

        // When
        $state = $this->breaker->state(self::SOURCE);

        // Then
        self::assertSame(CircuitState::HalfOpen, $state);
        self::assertSame(CircuitPermit::Trial, $this->breaker->tryAcquire(self::SOURCE));
        self::assertSame(CircuitPermit::Refused, $this->breaker->tryAcquire(self::SOURCE));
    }

    public function testKeepsCircuitsOfSourcesApart(): void
    {
        // Given
        $this->recordCalls(3, FailureKind::Unavailable);

        // When
        $permit = $this->breaker->tryAcquire(SourceName::Weather);

        // Then
        self::assertSame(CircuitPermit::Call, $permit);
        self::assertSame(CircuitState::Open, $this->breaker->state(self::SOURCE));
    }

    public function testStaysHalfOpenAfterASuccessfulCallStartedBeforeTheOpening(): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));

        // When
        $this->breaker->record(self::resultOf(self::OK, null), CircuitPermit::Call);

        // Then
        self::assertSame(CircuitState::HalfOpen, $this->breaker->state(self::SOURCE));
    }

    /**
     * @param list<array{string, string, ?string}>             $calls
     * @param list<array{Level, string, array<string, mixed>}> $expected
     */
    #[DataProvider('loggedCalls')]
    public function testLogsAnOpeningOnceWhenCallsOpenTheCircuit(array $calls, array $expected): void
    {
        // When
        foreach ($calls as [$at, $kind, $retryAt]) {
            $this->clock->modify(self::NOW.' '.$at);
            $this->breaker->record(self::resultOf($kind, self::moment($retryAt)), CircuitPermit::Call);
        }

        // Then
        self::assertSame($expected, $this->logged());
    }

    /**
     * @return iterable<string, array{list<array{string, string, ?string}>, list<array{Level, string, array<string, mixed>}>}>
     */
    public static function loggedCalls(): iterable
    {
        $unavailable = FailureKind::Unavailable->value;
        $rateLimited = FailureKind::RateLimited->value;

        yield 'two outages log nothing' => [self::outages(2, $unavailable), []];
        yield 'three outages log the opening with their count' => [self::outages(3, $unavailable), [self::opened(['source' => 'apod', 'kind' => 'unavailable', 'failures' => 3, 'openSeconds' => 60, 'trial' => false])]];
        yield 'outages after the opening log nothing more' => [self::outages(5, $unavailable), [self::opened(['source' => 'apod', 'kind' => 'unavailable', 'failures' => 3, 'openSeconds' => 60, 'trial' => false])]];
        yield 'a 429 logs the opening until Retry-After without a count' => [[[self::RIGHT_AWAY, $rateLimited, '+2 hours']], [self::opened(['source' => 'apod', 'kind' => 'rate_limited', 'openSeconds' => 7200, 'trial' => false])]];
        yield 'a 429 without Retry-After logs fifteen minutes' => [[[self::RIGHT_AWAY, $rateLimited, null]], [self::opened(['source' => 'apod', 'kind' => 'rate_limited', 'openSeconds' => 900, 'trial' => false])]];
        yield 'a Retry-After within a second rounds the opening up' => [[[self::RIGHT_AWAY, $rateLimited, '+90 seconds +1 millisecond']], [self::opened(['source' => 'apod', 'kind' => 'rate_limited', 'openSeconds' => 91, 'trial' => false])]];
        yield 'a later 429 lengthens the opening without logging it again' => [[[self::RIGHT_AWAY, $rateLimited, '+2 hours'], ['+30 seconds', $rateLimited, '+3 hours']], [self::opened(['source' => 'apod', 'kind' => 'rate_limited', 'openSeconds' => 7200, 'trial' => false])]];
        yield 'a 429 after outages opened it logs nothing more' => [[...self::outages(3, $unavailable), ['+30 seconds', $rateLimited, '+2 hours']], [self::opened(['source' => 'apod', 'kind' => 'unavailable', 'failures' => 3, 'openSeconds' => 60, 'trial' => false])]];
        yield 'a success logs nothing' => [[[self::RIGHT_AWAY, self::OK, null]], []];
        yield 'rejections log nothing' => [self::outages(3, FailureKind::Rejected->value), []];
    }

    public function testLogsAnOpeningFromHalfOpenAfterOutagesOfCallsStartedBeforeTheTrial(): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));

        // When
        $this->recordCalls(3, FailureKind::Unavailable);

        // Then
        self::assertSame([self::opened(['source' => 'apod', 'kind' => 'unavailable', 'failures' => 3, 'openSeconds' => 60, 'trial' => false])], $this->logged());
    }

    /**
     * @param list<array{Level, string, array<string, mixed>}> $expected
     */
    #[DataProvider('loggedTrials')]
    public function testLogsTheHalfOpenTrialAndHowItEnds(string $kind, ?string $retryAt, array $expected): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));
        $permit = $this->breaker->tryAcquire(self::SOURCE);

        // When
        $this->breaker->record(self::resultOf($kind, self::moment($retryAt)), $permit);

        // Then
        self::assertSame([[Level::Info, self::HALF_OPEN, ['source' => 'apod']], ...$expected], $this->logged());
    }

    /**
     * @return iterable<string, array{string, ?string, list<array{Level, string, array<string, mixed>}>}>
     */
    public static function loggedTrials(): iterable
    {
        yield 'a success closes it' => [self::OK, null, [[Level::Info, self::CLOSED, ['source' => 'apod']]]];
        yield 'a rejection closes it' => [FailureKind::Rejected->value, null, [[Level::Info, self::CLOSED, ['source' => 'apod']]]];
        yield 'an outage reopens it' => [FailureKind::Unavailable->value, null, [self::opened(['source' => 'apod', 'kind' => 'unavailable', 'openSeconds' => 60, 'trial' => true])]];
        yield 'a 429 reopens it until Retry-After' => [FailureKind::RateLimited->value, '+2 hours', [self::opened(['source' => 'apod', 'kind' => 'rate_limited', 'openSeconds' => 7200, 'trial' => true])]];
    }

    public function testLogsTheClosingOnceWhenTwoTrialsSucceed(): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));
        $first = $this->breaker->tryAcquire(self::SOURCE);
        $this->clock->modify(self::NOW.' +30 seconds');
        $second = $this->breaker->tryAcquire(self::SOURCE);
        $this->breaker->record(self::resultOf(self::OK, null), $first);
        $this->log->clear();

        // When
        $this->breaker->record(self::resultOf(self::OK, null), $second);

        // Then
        self::assertSame([CircuitPermit::Trial, CircuitPermit::Trial], [$first, $second]);
        self::assertSame([], $this->logged());
    }

    public function testLogsHalfOpenOnlyForTheCallerThatTakesTheTrial(): void
    {
        // Given
        self::openUntil($this->store, self::moment('-1 minute'));

        // When
        $permits = [$this->breaker->tryAcquire(self::SOURCE), $this->breaker->tryAcquire(self::SOURCE)];

        // Then
        self::assertSame([CircuitPermit::Trial, CircuitPermit::Refused], $permits);
        self::assertSame([[Level::Info, self::HALF_OPEN, ['source' => 'apod']]], $this->logged());
    }

    #[DataProvider('silentReads')]
    public function testLogsNothingWhenReadingOrRefusing(?string $openUntil): void
    {
        // Given
        self::openUntil($this->store, self::moment($openUntil));

        // When
        $this->breaker->state(self::SOURCE);
        $this->breaker->record(self::resultOf(FailureKind::RateLimited->value, null), CircuitPermit::Refused);

        // Then
        self::assertSame([], $this->logged());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function silentReads(): iterable
    {
        yield 'closed' => [null];
        yield 'open' => ['+30 seconds'];
        yield 'half-open' => ['-1 minute'];
    }

    private function recordCalls(int $count, FailureKind $kind): void
    {
        foreach (range(1, $count) as $ignored) {
            $this->breaker->record(self::resultOf($kind->value, null), CircuitPermit::Call);
        }
    }

    /**
     * @return list<array{Level, string, array<string, mixed>}>
     */
    private function logged(): array
    {
        return array_values(array_map(static fn (LogRecord $record): array => [$record->level, $record->message, $record->context], $this->log->getRecords()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{Level, string, array<string, mixed>}
     */
    private static function opened(array $context): array
    {
        return [Level::Warning, self::OPENED, $context];
    }

    private function takeTrialIf(bool $taken): void
    {
        if ($taken) {
            $this->store->claimTrial(self::SOURCE->value, $this->clock->now()->modify('+30 seconds'));
        }
    }

    private static function openUntil(InMemoryCircuitBreakerStore $store, ?\DateTimeImmutable $until): void
    {
        if ($until instanceof \DateTimeImmutable) {
            $store->open(self::SOURCE->value, $until);
        }
    }

    /**
     * @return list<array{string, string, null}>
     */
    private static function outages(int $count, string $kind): array
    {
        return array_fill(0, $count, [self::RIGHT_AWAY, $kind, null]);
    }

    private static function resultOf(string $kind, ?\DateTimeImmutable $retryAt): SourceResult
    {
        if (self::OK === $kind) {
            return SourceResult::ok(self::SOURCE, self::payload());
        }

        return SourceResult::failed(self::SOURCE, FailureKind::from($kind), retryAt: $retryAt);
    }

    private static function payload(): SourcePayload
    {
        return new FakePayload([]);
    }

    private static function moment(?string $offset): ?\DateTimeImmutable
    {
        if (null === $offset) {
            return null;
        }

        return new \DateTimeImmutable(self::NOW)->modify($offset);
    }
}
