<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use App\Briefing\Location;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\SourceClient;
use App\Briefing\Source\UpstreamResponseDecoder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OnThisDayClient implements SourceClient
{
    private const string PATH = 'feed/onthisday/%s/%s';
    private const string DAY_FORMAT = 'm/d';
    private const string SELECTED = 'selected';
    private const string BIRTHS = 'births';
    private const string DEATHS = 'deaths';

    public function __construct(
        #[Target('wikipedia.client')]
        private HttpClientInterface $wikipediaHttpClient,
        private UpstreamResponseDecoder $decoder,
        private OnThisDayMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        return true;
    }

    #[\NoDiscard]
    public function request(\DateTimeImmutable $date, Location $location): PendingFetch
    {
        $day = $date->format(self::DAY_FORMAT);
        $selected = $this->fetch(self::SELECTED, $day);
        $births = $this->fetch(self::BIRTHS, $day);
        $deaths = $this->fetch(self::DEATHS, $day);

        return new PendingFetch([$selected, $births, $deaths], $this->feedFrom($selected, $births, $deaths), $this->clock);
    }

    private function fetch(string $list, string $day): ResponseInterface
    {
        return $this->wikipediaHttpClient->request(Request::METHOD_GET, \sprintf(self::PATH, $list, $day));
    }

    /**
     * @return \Closure(): OnThisDayFeed
     */
    private function feedFrom(ResponseInterface $selected, ResponseInterface $births, ResponseInterface $deaths): \Closure
    {
        return fn (): OnThisDayFeed => $this->mapper->map($this->decoded($selected), $this->decoded($births), $this->decoded($deaths));
    }

    private function decoded(ResponseInterface $response): OnThisDayApiResponse
    {
        return $this->decoder->decode($response, OnThisDayApiResponse::class);
    }
}
