<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Message;

use App\Briefing\Message\PrefetchDateMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class QueuedPrefetchDateMessageTest extends TestCase
{
    private const string WITH_A_PLACE = 'O:36:\\"Symfony\\\\Component\\\\Messenger\\\\Envelope\\":2:{s:44:\\"\\0Symfony\\\\Component\\\\Messenger\\\\Envelope\\0stamps\\";a:0:{}s:45:\\"\\0Symfony\\\\Component\\\\Messenger\\\\Envelope\\0message\\";O:40:\\"App\\\\Briefing\\\\Message\\\\PrefetchDateMessage\\":3:{s:4:\\"date\\";s:10:\\"2024-03-15\\";s:8:\\"latitude\\";d:50.06;s:9:\\"longitude\\";d:19.94;}}';
    private const string AT_THE_DEFAULT_PLACE = 'O:36:\\"Symfony\\\\Component\\\\Messenger\\\\Envelope\\":2:{s:44:\\"\\0Symfony\\\\Component\\\\Messenger\\\\Envelope\\0stamps\\";a:0:{}s:45:\\"\\0Symfony\\\\Component\\\\Messenger\\\\Envelope\\0message\\";O:40:\\"App\\\\Briefing\\\\Message\\\\PrefetchDateMessage\\":3:{s:4:\\"date\\";s:10:\\"2024-03-15\\";s:8:\\"latitude\\";N;s:9:\\"longitude\\";N;}}';

    /**
     * @param array{date: string, latitude: ?float, longitude: ?float} $fields
     */
    #[DataProvider('queuedBodies')]
    public function testDecodesAMessageQueuedInTheStoredFormat(string $body, array $fields): void
    {
        // When
        $message = new PhpSerializer()->decode(['body' => $body])->getMessage();

        // Then
        self::assertInstanceOf(PrefetchDateMessage::class, $message);
        self::assertSame($fields, get_object_vars($message));
    }

    /**
     * @param array{date: string, latitude: ?float, longitude: ?float} $fields
     */
    #[DataProvider('queuedBodies')]
    public function testEncodesAMessageInTheStoredFormat(string $body, array $fields): void
    {
        // Given
        $message = new PrefetchDateMessage(...$fields);

        // When
        $encoded = new PhpSerializer()->encode(new Envelope($message));

        // Then
        self::assertSame(['body' => $body], $encoded);
    }

    /**
     * @return iterable<string, array{string, array{date: string, latitude: ?float, longitude: ?float}}>
     */
    public static function queuedBodies(): iterable
    {
        yield 'with a place' => [self::WITH_A_PLACE, ['date' => '2024-03-15', 'latitude' => 50.06, 'longitude' => 19.94]];
        yield 'at the default place' => [self::AT_THE_DEFAULT_PLACE, ['date' => '2024-03-15', 'latitude' => null, 'longitude' => null]];
    }
}
