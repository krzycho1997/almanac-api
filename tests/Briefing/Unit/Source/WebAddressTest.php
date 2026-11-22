<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\Source\WebAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAddressTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testAcceptsOnlyHttpsAddresses(string $url, bool $expected): void
    {
        // When
        $usable = WebAddress::isUsable($url);

        // Then
        self::assertSame($expected, $usable);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'https' => ['https://upload.wikimedia.org/a.jpg', true];
        yield 'http' => ['http://upload.wikimedia.org/a.jpg', false];
        yield 'script' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', false];
        yield 'without a scheme' => ['//upload.wikimedia.org/a.jpg', false];
        yield 'relative' => ['/a.jpg', false];
    }
}
