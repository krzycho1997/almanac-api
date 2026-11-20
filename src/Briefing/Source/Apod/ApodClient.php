<?php

declare(strict_types=1);

namespace App\Briefing\Source\Apod;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Location;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\SourceClient;
use App\Briefing\Source\UpstreamResponseDecoder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class ApodClient implements SourceClient
{
    private const string PATH = 'planetary/apod';
    private const string DATE_PARAMETER = 'date';
    private const string DATE_FORMAT = 'Y-m-d';
    private const string FIRST_PICTURE = '1995-06-16';
    private const string DATE_FIELD = 'date';
    private const string PUBLISHING_TIMEZONE = 'America/New_York';
    private const string QUERY = 'query';

    public function __construct(
        #[Target('nasa.client')]
        private HttpClientInterface $nasaHttpClient,
        private UpstreamResponseDecoder $decoder,
        private ApodMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        $day = $date->format(self::DATE_FORMAT);

        if ($day < self::FIRST_PICTURE) {
            return false;
        }

        return $day <= $this->publishedToday();
    }

    #[\NoDiscard]
    public function request(\DateTimeImmutable $date, Location $location): PendingFetch
    {
        $day = $date->format(self::DATE_FORMAT);

        return new PendingFetch($this->fetch($day), $this->pictureFor($day), $this->clock);
    }

    private function fetch(string $day): ResponseInterface
    {
        return $this->nasaHttpClient->request(Request::METHOD_GET, self::PATH, [
            self::QUERY => [self::DATE_PARAMETER => $day],
        ]);
    }

    /**
     * @return \Closure(ResponseInterface): ApodPicture
     */
    private function pictureFor(string $day): \Closure
    {
        return fn (ResponseInterface $response): ApodPicture => $this->pictureOf($response, $day);
    }

    private function pictureOf(ResponseInterface $response, string $day): ApodPicture
    {
        $picture = $this->mapper->map($this->decoder->decode($response, ApodApiResponse::class));

        if ($picture->date->format(self::DATE_FORMAT) === $day) {
            return $picture;
        }

        throw InvalidUpstreamPayloadException::at(self::DATE_FIELD, PayloadDefect::OtherDate, 'APOD answered with the picture of another date.');
    }

    private function publishedToday(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::PUBLISHING_TIMEZONE))->format(self::DATE_FORMAT);
    }
}
