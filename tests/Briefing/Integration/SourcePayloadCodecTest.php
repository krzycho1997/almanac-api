<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Exception\UnreadablePayloadException;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Model\WeatherKind;
use App\Briefing\SourceName;
use App\Briefing\SourcePayloadCodec;
use App\Tests\Support\ExposingKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

final class SourcePayloadCodecTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/snapshot/';
    private const string JSON = '.json';
    private const string WIKIPEDIA = 'https://en.wikipedia.org/wiki/';

    #[DataProvider('sources')]
    public function testWritesEveryPayloadInItsRecordedShape(SourceName $source): void
    {
        // When
        $data = self::codec()->encode(self::payloadOf($source));

        // Then
        self::assertSame(self::recorded($source), $data);
    }

    #[DataProvider('sources')]
    public function testReadsEveryRecordedPayloadBack(SourceName $source): void
    {
        // When
        $payload = self::codec()->decode($source, self::recorded($source));

        // Then
        self::assertEquals(self::payloadOf($source), $payload);
    }

    #[DataProvider('sources')]
    public function testReadsAPayloadWhoseKeysWereReorderedByTheDatabase(SourceName $source): void
    {
        // Given
        $data = self::reordered(self::recorded($source));

        // When
        $payload = self::codec()->decode($source, $data);

        // Then
        self::assertEquals(self::payloadOf($source), $payload);
    }

    /**
     * @return iterable<string, array{SourceName}>
     */
    public static function sources(): iterable
    {
        foreach (SourceName::cases() as $source) {
            yield $source->value => [$source];
        }
    }

    public function testReadsAWholeNumberStoredWithoutAFraction(): void
    {
        // Given
        $data = ['temperatureMin' => -3, 'temperatureMax' => 12] + self::recorded(SourceName::Weather);

        // When
        $weather = self::codec()->decode(SourceName::Weather, $data);

        // Then
        self::assertInstanceOf(DailyWeather::class, $weather);
        self::assertSame([-3.0, 12.0], [$weather->temperatureMin, $weather->temperatureMax]);
    }

    public function testReadsAnOlderPayloadWithoutAFieldThatHasADefault(): void
    {
        // Given
        $data = self::without('wikidataId', self::recorded(SourceName::OnThisDay));

        // When
        $feed = self::codec()->decode(SourceName::OnThisDay, $data);

        // Then
        self::assertInstanceOf(OnThisDayFeed::class, $feed);
        self::assertNull($feed->events[0]->pages[0]->wikidataId);
    }

    public function testWritesADateInUtc(): void
    {
        // Given
        $picture = self::picture(new \DateTimeImmutable('2024-03-15 00:00:00', new \DateTimeZone('Europe/Warsaw')));

        // When
        $data = self::codec()->encode($picture);

        // Then
        self::assertSame('2024-03-14T23:00:00Z', $data['date']);
    }

    public function testReadsADateWithAnOffsetInUtc(): void
    {
        // Given
        $data = ['date' => '2024-03-15T00:00:00+01:00'] + self::recorded(SourceName::Apod);

        // When
        $picture = self::codec()->decode(SourceName::Apod, $data);

        // Then
        self::assertInstanceOf(ApodPicture::class, $picture);
        self::assertSame('2024-03-14T23:00:00 UTC', $picture->date->format('Y-m-d\TH:i:s e'));
    }

    public function testIgnoresAFieldWrittenByANewerVersion(): void
    {
        // Given
        $data = ['uvIndexMax' => 5.5] + self::recorded(SourceName::Weather);

        // When
        $weather = self::codec()->decode(SourceName::Weather, $data);

        // Then
        self::assertEquals(self::payloadOf(SourceName::Weather), $weather);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('unreadablePayloads')]
    public function testRejectsAPayloadThatNoLongerMatchesTheModel(SourceName $source, array $data): void
    {
        // When
        $failure = self::failureToDecode($source, $data);

        // Then
        self::assertSame("The stored {$source->value} payload no longer matches the model.", $failure->getMessage());
        self::assertInstanceOf(ExceptionInterface::class, $failure->getPrevious());
    }

    /**
     * @return iterable<string, array{SourceName, array<array-key, mixed>}>
     */
    public static function unreadablePayloads(): iterable
    {
        $picture = self::recorded(SourceName::Apod);
        $weather = self::recorded(SourceName::Weather);

        yield 'unknown media type' => [SourceName::Apod, ['mediaType' => 'hologram'] + $picture];
        yield 'missing nullable field' => [SourceName::Apod, array_diff_key($picture, ['credit' => true])];
        yield 'missing attribution' => [SourceName::Weather, array_diff_key($weather, ['attribution' => true])];
        yield 'text where a number belongs' => [SourceName::Weather, ['temperatureMax' => 'warm'] + $weather];
        yield 'date that is not a date' => [SourceName::Apod, ['date' => 'yesterday-ish'] + $picture];
        yield 'relative date' => [SourceName::Apod, ['date' => 'tomorrow'] + $picture];
        yield 'date without a time' => [SourceName::Apod, ['date' => '2024-03-15'] + $picture];
        yield 'payload of another source' => [SourceName::Apod, $weather];
        yield 'list where an object belongs' => [SourceName::OnThisDay, ['attribution' => []] + self::recorded(SourceName::OnThisDay)];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function failureToDecode(SourceName $source, array $data): UnreadablePayloadException
    {
        try {
            self::codec()->decode($source, $data);
        } catch (UnreadablePayloadException $failure) {
            return $failure;
        }

        self::fail('The payload was read although it does not match the model.');
    }

    private static function codec(): SourcePayloadCodec
    {
        $codec = self::service(ExposingKernel::booted('test', [SourcePayloadCodec::class]), SourcePayloadCodec::class);
        self::assertInstanceOf(SourcePayloadCodec::class, $codec);

        return $codec;
    }

    private static function service(ContainerInterface $container, string $id): object
    {
        return $container->get($id);
    }

    private static function payloadOf(SourceName $source): SourcePayload
    {
        return match ($source) {
            SourceName::Apod => self::picture(),
            SourceName::OnThisDay => self::feed(),
            SourceName::Weather => self::weather(),
        };
    }

    private static function picture(?\DateTimeImmutable $date = null): ApodPicture
    {
        return new ApodPicture(
            $date ?? new \DateTimeImmutable('2024-03-15 00:00:00', new \DateTimeZone('UTC')),
            'The Tadpoles of IC 410',
            'Two tadpoles swim through the nebula.',
            MediaType::Image,
            'https://apod.nasa.gov/apod/image/2403/Tadpoles_1024.jpg',
            null,
            'Jane Doe',
            new Attribution('Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null),
        );
    }

    private static function feed(): OnThisDayFeed
    {
        return new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', self::WIKIPEDIA.'Julius_Caesar', 'https://upload.wikimedia.org/caesar.jpg', 'Q1048')])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', self::WIKIPEDIA.'Wings_Hauser', null)],
            [new NotablePerson(2023, 'Fred White', 'American musician', null, null)],
            new Attribution('Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
        );
    }

    private static function weather(): DailyWeather
    {
        return new DailyWeather(
            WeatherKind::Final,
            'Europe/Warsaw',
            -3.5,
            12.0,
            0.0,
            18.4,
            3,
            '2024-03-15T05:59:00+01:00',
            '2024-03-15T17:43:00+01:00',
            42240,
            new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information'),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function recorded(SourceName $source): array
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES.$source->value.self::JSON), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function without(string $key, array $data): array
    {
        unset($data[$key]);

        return array_map(static fn (mixed $value): mixed => \is_array($value) ? self::without($key, $value) : $value, $data);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function reordered(array $data): array
    {
        $reordered = array_map(static fn (mixed $value): mixed => \is_array($value) ? self::reordered($value) : $value, $data);

        if (array_is_list($reordered)) {
            return $reordered;
        }

        return array_reverse($reordered, true);
    }
}
