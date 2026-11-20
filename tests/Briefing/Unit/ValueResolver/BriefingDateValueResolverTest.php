<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\ValueResolver;

use App\Briefing\ValueResolver\BriefingDateValueResolver;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class BriefingDateValueResolverTest extends TestCase
{
    private const string ARGUMENT = 'date';
    private const string NOW = '2026-11-20 12:00';
    private const string TIMEZONE = 'Europe/Warsaw';

    public function testResolvesMidnightOfTheDateInTheApplicationTimeZone(): void
    {
        // Given
        $resolver = self::resolver();

        // When
        $dates = [...$resolver->resolve(self::requestFor('2026-11-21'), self::argument())];

        // Then
        self::assertEquals([new \DateTimeImmutable('2026-11-21 00:00', new \DateTimeZone(self::TIMEZONE))], $dates);
        self::assertSame(self::TIMEZONE, $dates[0]->getTimezone()->getName());
    }

    #[DataProvider('malformedDates')]
    public function testRejectsADateThatIsNotACalendarDateInYmdWithItsViolation(string $date): void
    {
        // Given
        $resolver = self::resolver();

        // When
        $exception = self::rejectionOf(BadRequestHttpException::class, static fn () => [...$resolver->resolve(self::requestFor($date), self::argument())]);

        // Then
        self::assertSame([self::ARGUMENT], self::violatedPaths($exception));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDates(): iterable
    {
        yield 'day after the end of the month' => ['2026-02-30'];
        yield 'single digits' => ['2026-2-3'];
        yield 'with a time' => ['2026-11-20T00:00'];
        yield 'relative' => ['today'];
        yield 'another format' => ['20.11.2026'];
        yield 'empty' => [''];
        yield 'null byte' => ["2026-11-20\0"];
        yield 'leading space' => [' 2026-11-20'];
        yield 'trailing new line' => ["2026-11-20\n"];
        yield 'signed year' => ['+2026-11-20'];
    }

    public function testRejectsADateOutsideTheWindowWithItsViolation(): void
    {
        // Given
        $resolver = self::resolver();

        // When
        $exception = self::rejectionOf(UnprocessableEntityHttpException::class, static fn () => [...$resolver->resolve(self::requestFor('2026-12-06'), self::argument())]);

        // Then
        self::assertSame([self::ARGUMENT], self::violatedPaths($exception));
    }

    public function testResolvesADayWithoutAMidnightToItsFirstMoment(): void
    {
        // Given
        $clock = new MockClock(new \DateTimeImmutable('2026-09-01 12:00', new \DateTimeZone('America/Santiago')));
        $resolver = new BriefingDateValueResolver(ValidatorWithClock::create($clock), $clock);

        // When
        $dates = [...$resolver->resolve(self::requestFor('2026-09-06'), self::argument())];

        // Then
        self::assertSame('2026-09-06', $dates[0]->format('Y-m-d'));
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private static function rejectionOf(string $expected, \Closure $resolution): \Throwable
    {
        try {
            $resolution();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception);

            return $exception;
        }

        self::fail('The date was accepted.');
    }

    /**
     * @return list<string>
     */
    private static function violatedPaths(\Throwable $exception): array
    {
        $previous = $exception->getPrevious();
        self::assertInstanceOf(ValidationFailedException::class, $previous);

        return array_map(static fn ($violation): string => $violation->getPropertyPath(), [...$previous->getViolations()]);
    }

    private static function resolver(): BriefingDateValueResolver
    {
        $clock = new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone(self::TIMEZONE)));

        return new BriefingDateValueResolver(ValidatorWithClock::create($clock), $clock);
    }

    private static function requestFor(string $date): Request
    {
        return new Request(attributes: [self::ARGUMENT => $date]);
    }

    private static function argument(): ArgumentMetadata
    {
        return new ArgumentMetadata(self::ARGUMENT, \DateTimeImmutable::class, false, false, null);
    }
}
