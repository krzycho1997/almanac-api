<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

abstract class CircuitBreakerStoreContract extends TestCase
{
    private const string CIRCUIT = 'apod';
    private const string OTHER_CIRCUIT = 'weather';
    private const string LATER = '+1 minute';
    private const string EARLIER = '-1 second';
    private const string SOON = '+50 milliseconds';
    private const int A_WHILE_MS = 30;
    private const int PAST_THE_WINDOW_MS = 60;

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
        $store = $this->store();
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
        $store = $this->store();
        $store->claimTrial(self::CIRCUIT, $this->at(self::EARLIER));

        // When
        $claimed = $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertTrue($claimed);
    }

    public function testKeepsALeaseAgainstAClaimThatWouldEndAtOnce(): void
    {
        // Given
        $store = $this->store();
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
        $store = $this->store();
        $store->claimTrial(self::CIRCUIT, $this->at(self::LATER));

        // When
        $store->open(self::CIRCUIT, $this->at(self::LATER));

        // Then
        self::assertTrue($store->claimTrial(self::CIRCUIT, $this->at(self::LATER)));
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
        self::assertTrue($store->claimTrial(self::CIRCUIT, $this->at(self::LATER)));
        self::assertSame(1, $store->recordFailure(self::CIRCUIT, $this->at(self::LATER)));
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->clock()->now()->modify($modifier);
    }
}
