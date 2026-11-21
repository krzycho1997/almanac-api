<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

abstract class CircuitBreakerStoreContract extends TestCase
{
    private const string CIRCUIT = 'apod';
    private const string OTHER_CIRCUIT = 'weather';
    private const string LATER = '+1 minute';
    private const string MUCH_LATER = '+1 hour';
    private const string EARLIER = '-1 second';
    private const string RIGHT_NOW = '+0 seconds';
    private const string SOON = '+500 milliseconds';
    private const int A_WHILE_MS = 300;
    private const int PAST_THE_WINDOW_MS = 600;

    abstract protected function store(): CircuitBreakerStore;

    abstract protected function clock(): ClockInterface;

    abstract protected function elapse(int $milliseconds): void;

    public function testCountsFailuresInARow(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $count = $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertSame(2, $count);
        self::assertSame(1, $store->recordFailure(self::OTHER_CIRCUIT, $this->at(self::LATER)));
    }

    public function testForgetsFailuresAtTheGivenInstant(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::EARLIER));

        // When
        $count = $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertSame(1, $count);
    }

    public function testStartsCountingAgainAfterAFailureForgottenAtOnce(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $forgotten = $store->recordFailure(self::CIRCUIT, $this->at(self::EARLIER));

        // Then
        self::assertSame(1, $forgotten);
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testForgetsFailuresOnRequest(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $store->forgetFailures(self::CIRCUIT);

        // Then
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testForgetsFailuresOnceTheirWindowHasPassed(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::SOON));

        // When
        $this->elapse(self::PAST_THE_WINDOW_MS);

        // Then
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::SOON)));
    }

    public function testMovesTheWindowWithEveryFailure(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::SOON));
        $this->elapse(self::A_WHILE_MS);
        $store->recordFailure(self::CIRCUIT, $this->at(self::SOON));

        // When
        $this->elapse(self::A_WHILE_MS);

        // Then
        self::assertSame(3, $store->recordFailure(self::CIRCUIT, $this->at(self::SOON)));
    }

    public function testReturnsTheOpeningInTheTimezoneOfTheClock(): void
    {
        // Given
        $store = $this->store();
        $until = $this->at(self::LATER);

        // When
        $store->open(self::CIRCUIT, $until);

        // Then
        self::assertSame($until->format(\DateTimeInterface::RFC3339_EXTENDED), $store->openUntil(self::CIRCUIT)?->format(\DateTimeInterface::RFC3339_EXTENDED));
    }

    public function testRemembersWhenTheCircuitOpensUntilItCloses(): void
    {
        // Given
        $store = $this->store();
        $until = $this->at(self::LATER);

        // When
        $store->open(self::CIRCUIT, $until);

        // Then
        self::assertEquals($until, $store->openUntil(self::CIRCUIT));
        self::assertNull($store->openUntil(self::OTHER_CIRCUIT));
    }

    public function testGrantsOneTrialPerLease(): void
    {
        // Given
        $store = $this->halfOpenStore();
        $first = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // When
        $second = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function testGrantsATrialAgainOnceTheLeaseHasExpired(): void
    {
        // Given
        $store = $this->halfOpenStore();
        $store->claimTrial(self::CIRCUIT, $this->at(self::EARLIER));

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertTrue($claimed);
    }

    public function testKeepsALeaseAgainstAClaimThatWouldEndAtOnce(): void
    {
        // Given
        $store = $this->halfOpenStore();
        $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::EARLIER));

        // Then
        self::assertFalse($claimed);
    }

    public function testOpeningForgetsTheFailures(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $store->open(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testReopeningReleasesTheTrial(): void
    {
        // Given
        $store = $this->halfOpenStore();
        $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));
        $store->open(self::CIRCUIT, $this->at(self::SOON));

        // When
        $this->elapse(self::PAST_THE_WINDOW_MS);

        // Then
        self::assertTrue($store->claimTrial(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testRefusesATrialWhileTheCircuitIsClosed(): void
    {
        // Given
        $store = $this->store();

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertFalse($claimed);
    }

    public function testRefusesATrialWhileTheCircuitIsOpen(): void
    {
        // Given
        $store = $this->halfOpenStore();
        $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));
        $store->open(self::CIRCUIT, $this->at(self::LATER));

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertFalse($claimed);
    }

    public function testClosingForgetsEverything(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));
        $store->open(self::CIRCUIT, $this->at(self::LATER));
        $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // When
        $store->close(self::CIRCUIT);

        // Then
        self::assertNull($store->openUntil(self::CIRCUIT));
        self::assertFalse($store->claimTrial(self::CIRCUIT, $this->at(self::LATER)));
        $store->open(self::CIRCUIT, $this->at(self::EARLIER));
        self::assertTrue($store->claimTrial(self::CIRCUIT, $this->at(self::LATER)));
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    /**
     * @param list<string> $before
     */
    #[DataProvider('openings')]
    public function testTellsWhetherOpeningStartsAnOpening(array $before, bool $expected): void
    {
        // Given
        $store = $this->store();
        foreach ($before as $until) {
            $store->open(self::CIRCUIT, $this->at($until));
        }

        // When
        $opened = $store->open(self::CIRCUIT, $this->at(self::MUCH_LATER));

        // Then
        self::assertSame($expected, $opened);
        self::assertEquals($this->at(self::MUCH_LATER), $store->openUntil(self::CIRCUIT));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function openings(): iterable
    {
        yield 'a closed circuit' => [[], true];
        yield 'an open circuit' => [[self::LATER], false];
        yield 'a half-open circuit' => [[self::EARLIER], true];
        yield 'a circuit open until exactly now' => [['+0 seconds'], true];
    }

    public function testTellsAnOpeningAfterTheCircuitClosed(): void
    {
        // Given
        $store = $this->store();
        $store->open(self::CIRCUIT, $this->at(self::LATER));
        $store->close(self::CIRCUIT);

        // When
        $opened = $store->open(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertTrue($opened);
        self::assertTrue($store->open(self::OTHER_CIRCUIT, $this->at(self::LATER)));
    }

    public function testKeepsALongerOpeningAgainstAShorterOne(): void
    {
        // Given
        $store = $this->store();
        $store->open(self::CIRCUIT, $this->at(self::MUCH_LATER));
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $opened = $store->open(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertFalse($opened);
        self::assertEquals($this->at(self::MUCH_LATER), $store->openUntil(self::CIRCUIT));
        self::assertSame(2, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testTellsWhetherClosingEndedAnOpening(): void
    {
        // Given
        $store = $this->store();
        $store->open(self::CIRCUIT, $this->at(self::EARLIER));

        // When
        $first = $store->close(self::CIRCUIT);
        $second = $store->close(self::CIRCUIT);

        // Then
        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function testKeepsTheFailuresAgainstAnOpeningUntilTheSameInstant(): void
    {
        // Given
        $store = $this->store();
        $store->open(self::CIRCUIT, $this->at(self::MUCH_LATER));
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $opened = $store->open(self::CIRCUIT, $this->at(self::MUCH_LATER));

        // Then
        self::assertFalse($opened);
        self::assertSame(2, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testForgetsAFailureWhoseWindowEndsRightNow(): void
    {
        // Given
        $store = $this->store();
        $store->recordFailure(self::CIRCUIT, $this->at(self::LATER));

        // When
        $count = $store->recordFailure(self::CIRCUIT, $this->at(self::RIGHT_NOW));

        // Then
        self::assertSame(1, $count);
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    public function testGrantsATrialWhoseLeaseEndsRightNowWhenNoneIsLeased(): void
    {
        // Given
        $store = $this->halfOpenStore();

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::RIGHT_NOW));

        // Then
        self::assertTrue($claimed);
    }

    private function halfOpenStore(): CircuitBreakerStore
    {
        $store = $this->store();
        $store->open(self::CIRCUIT, $this->at(self::EARLIER));

        return $store;
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->clock()->now()->modify($modifier);
    }
}
