<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Discovery;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Discovery\DiscoveryRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryRecordTest extends TestCase
{
    private const string ID = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string DATE = '2024-03-15';
    private const string TITLE = 'Portrait of NGC 1055';

    #[DataProvider('validRecords')]
    public function testAcceptsARecordThatKeepsTheRules(DiscoveryType $type, string $key, ?\DateTimeImmutable $date): void
    {
        // When
        $record = self::record($type, $key, $date, self::TITLE);

        // Then
        self::assertSame($key, $record->key);
    }

    /**
     * @return iterable<string, array{DiscoveryType, string, ?\DateTimeImmutable}>
     */
    public static function validRecords(): iterable
    {
        yield 'picture of the day with its date' => [DiscoveryType::Apod, 'apod:2024-03-15', new \DateTimeImmutable(self::DATE)];
        yield 'event with its date' => [DiscoveryType::Event, 'event:1939-03-15:55ad9960237be57d', new \DateTimeImmutable('1939-03-15')];
        yield 'event without a date' => [DiscoveryType::Event, 'event:03-15:55ad9960237be57d', null];
        yield 'person without a date' => [DiscoveryType::Person, 'person:Q1048', null];
    }

    #[DataProvider('invalidRecords')]
    public function testRejectsARecordThatBreaksARule(DiscoveryType $type, string $key, ?\DateTimeImmutable $date, string $title, string $reason): void
    {
        // Then / When
        $this->expectExceptionObject(new \InvalidArgumentException($reason));
        self::record($type, $key, $date, $title);
    }

    /**
     * @return iterable<string, array{DiscoveryType, string, ?\DateTimeImmutable, string, string}>
     */
    public static function invalidRecords(): iterable
    {
        yield 'empty title' => [DiscoveryType::Person, 'person:Q1048', null, '', 'A discovery needs a title.'];
        yield 'key of another type' => [DiscoveryType::Apod, 'event:2024-03-15', new \DateTimeImmutable(self::DATE), self::TITLE, 'Keys of apod discoveries are "apod:" followed by what they name.'];
        yield 'key without the separator' => [DiscoveryType::Person, 'personQ1048', null, self::TITLE, 'Keys of person discoveries are "person:" followed by what they name.'];
        yield 'key that names nothing' => [DiscoveryType::Apod, 'apod:', new \DateTimeImmutable(self::DATE), self::TITLE, 'Keys of apod discoveries are "apod:" followed by what they name.'];
        yield 'picture of the day without a date' => [DiscoveryType::Apod, 'apod:2024-03-15', null, self::TITLE, 'A picture of the day needs its date.'];
    }

    private static function record(DiscoveryType $type, string $key, ?\DateTimeImmutable $date, string $title): DiscoveryRecord
    {
        return new DiscoveryRecord(DiscoveryId::fromString(self::ID), $key, $type, $date, $title, null, null);
    }
}
