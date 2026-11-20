<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

final class SerializerDefaultsTest extends KernelTestCase
{
    public function testWritesJsonTheSameWayForTheWholeApi(): void
    {
        // Given
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $value = [
            'at' => new \DateTimeImmutable('2026-11-18 12:00', new \DateTimeZone('Europe/Warsaw')),
            'link' => 'https://apod.nasa.gov/apod/',
            'name' => 'Grudić',
            'latitude' => 52.0,
        ];

        // When
        $json = $serializer->serialize($value, JsonEncoder::FORMAT);

        // Then
        self::assertSame('{"at":"2026-11-18T11:00:00Z","link":"https://apod.nasa.gov/apod/","name":"Grudić","latitude":52.0}', $json);
    }
}
