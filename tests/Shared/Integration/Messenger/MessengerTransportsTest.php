<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Yaml\Yaml;

final class MessengerTransportsTest extends KernelTestCase
{
    private const string FAILED = 'messenger.transport.failed';
    private const string ASYNC = 'messenger.transport.async';

    public function testKeepsFailedMessagesInTheMigratedTableUnderTheirOwnQueue(): void
    {
        // Given
        $failed = self::transport(self::FAILED);
        $message = new \ArrayObject(['date' => '2024-03-15']);

        // When
        $failed->send(new Envelope($message));

        // Then
        $received = [...$failed->get()];
        self::assertCount(1, $received);
        self::assertEquals($message, $received[0]->getMessage());
        self::assertSame([['queue_name' => 'failed']], self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT queue_name FROM messenger_messages'));
    }

    public function testKeepsTheTwoQueuesApartWithoutCreatingTablesOutsideTests(): void
    {
        // Given
        $config = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir').'/config/packages/messenger.yaml');

        // When
        $async = [self::at($config, 'framework', 'messenger', 'transports', 'async', 'dsn'), self::at($config, 'framework', 'messenger', 'transports', 'async', 'options')];
        $failed = [self::at($config, 'framework', 'messenger', 'transports', 'failed', 'dsn'), self::at($config, 'framework', 'messenger', 'transports', 'failed', 'options')];

        // Then
        self::assertSame(['%env(MESSENGER_TRANSPORT_DSN)%', ['queue_name' => 'async', 'redeliver_timeout' => 300]], $async);
        self::assertSame(['doctrine://default?auto_setup=0', ['queue_name' => 'failed']], $failed);
        self::assertSame('doctrine://default?auto_setup=0', $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? null);
    }

    public function testQueuesAsyncMessagesInMemoryInTests(): void
    {
        // When
        $async = self::transport(self::ASYNC);

        // Then
        self::assertInstanceOf(InMemoryTransport::class, $async);
    }

    private static function at(mixed $tree, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $tree = \is_array($tree) ? $tree[$key] ?? null : null;
        }

        return $tree;
    }

    private static function transport(string $id): TransportInterface
    {
        $transport = self::getContainer()->get($id);
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }
}
