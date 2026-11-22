<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Validator;

use App\Briefing\Dto\Input\PrefetchInput;
use App\Briefing\Validator\ValidPrefetchRange;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintViolationInterface;

final class ValidPrefetchRangeTest extends TestCase
{
    private const string NOW = '2026-11-21 10:00';

    /**
     * @param list<array{string, string, ?string}> $expected
     */
    #[DataProvider('ranges')]
    public function testChecksTheRangeOfValidDates(string $from, string $to, array $expected): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = $from;
        $input->to = $to;

        // When
        $violations = ValidatorWithClock::create(new MockClock(self::NOW))->validate($input);

        // Then
        self::assertSame($expected, array_map(self::describe(...), iterator_to_array($violations)));
    }

    /**
     * @return iterable<string, array{string, string, list<array{string, string, ?string}>}>
     */
    public static function ranges(): iterable
    {
        yield 'one day' => ['2024-03-15', '2024-03-15', []];
        yield 'exactly the longest' => ['2024-01-01', '2024-12-31', []];
        yield 'ends before it starts' => ['2024-03-15', '2024-03-14', [['to', 'The last date must not be before the first.', ValidPrefetchRange::ENDS_BEFORE_IT_STARTS_ERROR]]];
        yield 'one day too long' => ['2024-01-01', '2025-01-01', [['to', 'The range may span at most 366 days.', ValidPrefetchRange::TOO_LONG_ERROR]]];
    }

    #[DataProvider('invalidDates')]
    public function testLeavesAnInvalidDateToItsOwnConstraint(string $from, string $to): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = $from;
        $input->to = $to;

        // When
        $violations = ValidatorWithClock::create(new MockClock(self::NOW))->validate($input, new ValidPrefetchRange());

        // Then
        self::assertCount(0, $violations);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'invalid first date' => ['2024-02-30', '2023-01-01'];
        yield 'invalid last date' => ['2024-03-15', '2023-02-30'];
    }

    /**
     * @return array{string, string, ?string}
     */
    private static function describe(ConstraintViolationInterface $violation): array
    {
        return [$violation->getPropertyPath(), (string) $violation->getMessage(), $violation->getCode()];
    }
}
