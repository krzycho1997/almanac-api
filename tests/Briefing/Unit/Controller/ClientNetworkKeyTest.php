<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Controller;

use App\Briefing\Controller\ClientNetworkKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClientNetworkKeyTest extends TestCase
{
    private const string PATH = '/api/briefings/2024-03-15';
    private const string REMOTE_ADDR = 'REMOTE_ADDR';

    #[DataProvider('clients')]
    public function testKeysTheClientByItsNetwork(string $clientIp, string $key): void
    {
        // Given
        $request = Request::create(self::PATH, server: [self::REMOTE_ADDR => $clientIp]);

        // When
        $actual = ClientNetworkKey::fromRequest([], $request);

        // Then
        self::assertSame($key, $actual);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function clients(): iterable
    {
        yield 'IPv4 address as it is' => ['192.0.2.10', '192.0.2.10'];
        yield 'IPv6 address cut to its /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::'];
        yield 'IPv4-mapped IPv6 address' => ['::ffff:192.0.2.10', '::ffff:192.0.2.10'];
        yield 'unix socket instead of an address' => ['unix:', ClientNetworkKey::UNKNOWN_CLIENT];
        yield 'empty address' => ['', ClientNetworkKey::UNKNOWN_CLIENT];
    }

    public function testKeysAClientWithoutAddressAsUnknown(): void
    {
        // Given
        $request = Request::create(self::PATH);
        $request->server->remove(self::REMOTE_ADDR);

        // When
        $key = ClientNetworkKey::fromRequest([], $request);

        // Then
        self::assertSame(ClientNetworkKey::UNKNOWN_CLIENT, $key);
    }
}
