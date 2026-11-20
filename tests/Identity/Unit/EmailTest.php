<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit;

use App\Identity\Email;
use App\Identity\Exception\InvalidEmailException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[DataProvider('spellings')]
    public function testStoresOneSpellingOfAnAddress(string $spelling): void
    {
        // When
        $email = Email::fromString($spelling);

        // Then
        self::assertSame('ada@example.com', $email->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellings(): iterable
    {
        yield 'canonical' => ['ada@example.com'];
        yield 'upper case' => ['ADA@Example.COM'];
        yield 'surrounding whitespace' => ["  ada@example.com\n"];
    }

    public function testLowersNonAsciiLetters(): void
    {
        // When
        $email = Email::fromString('ŻÓŁW@Example.pl');

        // Then
        self::assertSame('żółw@example.pl', $email->value);
    }

    #[DataProvider('malformedAddresses')]
    public function testRejectsAnAddressThatIsNotShapedLikeOne(string $address): void
    {
        // Then / When
        $this->expectException(InvalidEmailException::class);
        Email::fromString($address);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAddresses(): iterable
    {
        yield 'blank' => [" \t"];
        yield 'no at sign' => ['ada.example.com'];
        yield 'two at signs' => ['ada@lovelace@example.com'];
        yield 'nothing before the at sign' => ['@example.com'];
        yield 'nothing after the at sign' => ['ada@'];
        yield 'space inside' => ['ada lovelace@example.com'];
        yield 'tab inside' => ["ada\t@example.com"];
        yield 'no-break space inside' => ["ada\u{00A0}@example.com"];
        yield 'zero-width space inside' => ["ada\u{200B}@example.com"];
        yield 'NUL byte' => ["ada@example.com\0"];
        yield 'invalid UTF-8' => ["\xFF\xFE@example.com"];
        yield 'one character too long' => [str_repeat('a', 243).'@example.com'];
    }

    #[DataProvider('longestStorableAddresses')]
    public function testAcceptsTheLongestStorableAddress(string $address): void
    {
        // When
        $email = Email::fromString($address);

        // Then
        self::assertSame(Email::MAX_LENGTH, mb_strlen($email->value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longestStorableAddresses(): iterable
    {
        yield 'one byte per character' => [str_repeat('a', 242).'@example.com'];
        yield 'two bytes per character' => [str_repeat('ż', 242).'@example.com'];
    }
}
