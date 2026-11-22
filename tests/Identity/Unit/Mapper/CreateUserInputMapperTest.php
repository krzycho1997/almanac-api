<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Mapper;

use App\Identity\Dto\Input\CreateUserInput;
use App\Identity\Email;
use App\Identity\Mapper\CreateUserInputMapper;
use PHPUnit\Framework\TestCase;

final class CreateUserInputMapperTest extends TestCase
{
    public function testConvertsTheAddressToAnEmail(): void
    {
        // Given
        $input = new CreateUserInput();
        $input->email = 'Reader@Example.com';

        // When
        $email = new CreateUserInputMapper()->convertToEmail($input);

        // Then
        self::assertEquals(Email::fromString('Reader@Example.com'), $email);
    }

    public function testConvertsTheLifetimeToAnInterval(): void
    {
        // Given
        $input = new CreateUserInput();
        $input->ttl = '12h';

        // When
        $ttl = new CreateUserInputMapper()->convertToTtl($input);

        // Then
        self::assertSame(12, $ttl->h);
    }

    public function testConvertsTheDefaultLifetimeOfThirtyDays(): void
    {
        // When
        $ttl = new CreateUserInputMapper()->convertToTtl(new CreateUserInput());

        // Then
        self::assertSame(30 * 24, $ttl->h);
    }

    public function testRefusesALifetimeThatSkippedValidation(): void
    {
        // Given
        $input = new CreateUserInput();
        $input->ttl = 'soon';

        // Then / When
        $this->expectException(\LogicException::class);

        new CreateUserInputMapper()->convertToTtl($input);
    }
}
