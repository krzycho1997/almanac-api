<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

use App\Briefing\Source\Apod\ApodSource;
use App\Briefing\Source\OnThisDay\OnThisDaySource;
use App\Briefing\Source\OpenMeteo\WeatherSource;
use App\Briefing\Source\Source;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use Symfony\Component\HttpClient\Response\MockResponse;

final readonly class ClientProbe
{
    private const string ARCHIVE_HOST = 'archive-api.open-meteo.com';

    public function __construct(
        private UpstreamMockResponseFactory $upstream,
        private ApodSource $apod,
        private OnThisDaySource $onThisDay,
        private WeatherSource $weather,
        private FixtureFiles $files,
    ) {
    }

    public function resolve(UpstreamFixture $fixture, string $body): SourceResult
    {
        $call = self::callOf($fixture);
        $this->upstream->respondWith($this->scenario($fixture, $call, $body));
        $source = $this->sourceFor($call->source);

        return $source->client()->request($call->date(), $call->location())->resolve($call->source);
    }

    /**
     * @return list<string>
     */
    public function requestedUrls(): array
    {
        return array_map(static fn (MockResponse $response): string => $response->getRequestUrl(), $this->upstream->served());
    }

    private static function callOf(UpstreamFixture $fixture): ClientCall
    {
        if ($fixture->call instanceof ClientCall) {
            return $fixture->call;
        }

        throw new \LogicException("No client requests {$fixture->path}.");
    }

    private function scenario(UpstreamFixture $fixture, ClientCall $call, string $body): UpstreamMock
    {
        $scenario = UpstreamMock::create();

        foreach (UpstreamFixtures::requestedTogetherWith($call) as $requested) {
            $scenario = self::queueFor($scenario, $call)->json($this->bodyOf($requested, $fixture, $body))->status($requested->expectedStatus);
        }

        return $scenario;
    }

    private function bodyOf(UpstreamFixture $requested, UpstreamFixture $fixture, string $body): string
    {
        if ($requested->path === $fixture->path) {
            return $body;
        }

        return $this->files->contents($requested->path);
    }

    private static function queueFor(UpstreamMock $scenario, ClientCall $call): UpstreamMock
    {
        return match ($call->source) {
            SourceName::Apod => $scenario->apod(),
            SourceName::OnThisDay => $scenario->onThisDay(),
            SourceName::Weather => self::weatherQueue($scenario, $call),
        };
    }

    private static function weatherQueue(UpstreamMock $scenario, ClientCall $call): UpstreamMock
    {
        if (self::ARCHIVE_HOST === $call->host()) {
            return $scenario->archive();
        }

        return $scenario->forecast();
    }

    private function sourceFor(SourceName $source): Source
    {
        return match ($source) {
            SourceName::Apod => $this->apod,
            SourceName::OnThisDay => $this->onThisDay,
            SourceName::Weather => $this->weather,
        };
    }
}
