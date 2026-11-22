<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Discovery;

use App\Briefing\Discovery\DiscoveryExtractor;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\SourceName;
use App\Briefing\SourcePayloadCodec;
use App\Tests\Support\InteractsWithTestServices;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StoredDiscoveryKeysTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string DAY = '2024-03-15';
    private const array ATTRIBUTION = ['name' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/', 'license' => 'CC BY-SA 4.0', 'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'modified' => true, 'notice' => null];

    public function testGivesAStoredPictureOfTheDayItsKeyAndId(): void
    {
        // Given
        $stored = [
            'date' => '2024-03-15T00:00:00-04:00',
            'title' => 'Portrait of NGC 1055',
            'explanation' => 'A galaxy.',
            'mediaType' => 'image',
            'url' => 'https://apod.nasa.gov/apod/image/2403/NGC1055.jpg',
            'hdUrl' => null,
            'credit' => null,
            'attribution' => ['name' => 'NASA APOD', 'url' => 'https://apod.nasa.gov/', 'license' => null, 'licenseUrl' => null, 'modified' => false, 'notice' => null],
        ];

        // When
        $keys = self::keysOf(SourceName::Apod, $stored);

        // Then
        self::assertSame(['apod:2024-03-15' => 'c05f9b6a-9262-5859-b45d-743a62e1bc6e'], $keys);
    }

    public function testGivesStoredEventsAndPeopleTheirKeysAndIds(): void
    {
        // Given
        $stored = [
            'events' => [
                ['year' => 1939, 'text' => 'Germany occupies Bohemia.', 'pages' => [['title' => 'Bohemia', 'url' => 'https://en.wikipedia.org/wiki/Bohemia', 'thumbnail' => null, 'wikidataId' => 'Q39193']]],
            ],
            'births' => [
                self::person('Julius Caesar', 'https://en.wikipedia.org/wiki/Julius_Caesar', 'Q1048'),
                self::person('John Smith', 'https://en.wikipedia.org/wiki/John_Smith_(footballer)', null),
                self::person('Frédéric Chopin', 'https://en.wikipedia.org/wiki/Fr%C3%A9d%C3%A9ric_Chopin', null),
                self::person('Jane Doe', 'https://en.wikipedia.org/wiki/List_of_people#Doe', null),
            ],
            'deaths' => [
                self::person('Anne Smith', 'https://en.wikipedia.org/wiki/Anne_Smith_(writer)', 'Q'.str_repeat('7', 300)),
                self::person('Mary Roe', null, null),
                self::person('Long Title', 'https://en.wikipedia.org/wiki/'.str_repeat('A', 300), null),
            ],
            'attribution' => self::ATTRIBUTION,
        ];

        // When
        $keys = self::keysOf(SourceName::OnThisDay, $stored);

        // Then
        self::assertSame([
            'event:1939-03-15:55ad9960237be57d' => '1604e20a-544e-5ab3-97a4-e7861e6ea31a',
            'person:Q1048' => '30ad2d96-39f2-514a-806e-58f2041d1ce6',
            'person:page:John Smith (footballer)' => 'fa271102-bc32-56f2-b685-1b0b4ce8dcc5',
            'person:page:Frédéric Chopin' => 'ca87ec79-3296-5b53-960b-529ff4b6966d',
            'person:#1900:cac7bbb6b67b44ea' => 'bfce6dcc-441f-50a7-9490-7f8b70036c8b',
            'person:page:Anne Smith (writer)' => '077ab015-1ffe-5e39-a926-701e67a735bc',
            'person:#1900:b29f8c6a53ae6e59' => '14aaed02-2ba5-56d4-8275-2f4bb95468cd',
            'person:#1900:49d17ca4c3dc0a3f' => 'ead72903-bf4a-55fc-9061-1728eaf377d2',
        ], $keys);
    }

    /**
     * @return array<string, mixed>
     */
    private static function person(string $name, ?string $url, ?string $wikidataId): array
    {
        return ['year' => 1900, 'name' => $name, 'description' => null, 'url' => $url, 'thumbnail' => null, 'wikidataId' => $wikidataId];
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, string>
     */
    private static function keysOf(SourceName $source, array $stored): array
    {
        $payload = self::testService(SourcePayloadCodec::class)->decode($source, $stored);
        $records = self::testService(DiscoveryExtractor::class)->extract($payload, new \DateTimeImmutable(self::DAY));

        return array_combine(
            array_map(static fn (DiscoveryRecord $record): string => $record->key, $records),
            array_map(static fn (DiscoveryRecord $record): string => $record->id->toRfc4122(), $records),
        );
    }
}
