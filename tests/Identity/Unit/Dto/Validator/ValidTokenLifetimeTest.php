<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Dto\Validator;

use App\Identity\Dto\Input\CreateUserInput;
use App\Identity\Dto\Input\Validator\ValidTokenLifetime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

final class ValidTokenLifetimeTest extends TestCase
{
    /**
     * @param list<array{string, string, ?string}> $expected
     */
    #[DataProvider('lifetimes')]
    public function testAcceptsLifetimesUpToAYear(string $ttl, array $expected): void
    {
        // Given
        $input = new CreateUserInput();
        $input->ttl = $ttl;

        // When
        $violations = Validation::createValidator()->validate($input, new ValidTokenLifetime());

        // Then
        self::assertSame($expected, array_map(self::describe(...), iterator_to_array($violations)));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string, ?string}>}>
     */
    public static function lifetimes(): iterable
    {
        $tooLong = [['ttl', 'The lifetime must be between 1h and 365d.', ValidTokenLifetime::TOO_LONG_ERROR]];

        yield 'one hour' => ['1h', []];
        yield 'a year in days' => ['365d', []];
        yield 'a year in hours' => ['8760h', []];
        yield 'one hour over a year' => ['8761h', $tooLong];
        yield 'a day over a year' => ['366d', $tooLong];
        yield 'not a lifetime' => ['soon', []];
    }

    /**
     * @return array{string, string, ?string}
     */
    private static function describe(ConstraintViolationInterface $violation): array
    {
        return [$violation->getPropertyPath(), (string) $violation->getMessage(), $violation->getCode()];
    }
}
