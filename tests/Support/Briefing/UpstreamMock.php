<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpstreamMock
{
    private const string EMPTY_JSON = '{}';
    private const string IDLE_TIMEOUT = '';
    private const string HTTP_CODE = 'http_code';
    private const string RESPONSE_HEADERS = 'response_headers';
    private const string RETRY_AFTER = 'Retry-After';
    private const string ERROR = 'error';
    private const string CONNECTION_REFUSED = 'Connection refused';
    private const string MAX_DURATION_REACHED = 'Max duration was reached';
    private const string TOTAL_TIME = 'total_time';
    private const float MAX_DURATION_SECONDS = 3.0;
    private const string FIXTURES = __DIR__.'/../../Fixtures/upstream/';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string JSON = 'application/json';
    private const array ON_THIS_DAY_LISTS = ['selected', 'births', 'deaths'];
    private const string ON_THIS_DAY_FIXTURE = 'on_this_day/%s-%s.json';
    private const string WEATHER_TEMPLATE = 'open_meteo/archive-2024-03-15.json';
    private const string WEATHER_TEMPLATE_DAY = '2024-03-15';
    private const string UTC_MIDNIGHT = ' 00:00 UTC';
    private const array WEATHER_TIMES = ['time', 'sunrise', 'sunset'];
    private const string NASA = 'nasa';
    private const string WIKIPEDIA = 'wikipedia';
    private const string OPEN_METEO = 'open_meteo';
    private const string OPEN_METEO_ARCHIVE = 'open_meteo_archive';
    private const string PICK_THE_UPSTREAM_FIRST = 'Pick the upstream first, e.g. UpstreamMock::create()->apod().';

    /**
     * @param array<string, list<array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}}>> $responses
     */
    private function __construct(
        private array $responses,
        private ?string $upstream,
    ) {
    }

    public static function create(): self
    {
        return new self([], null);
    }

    public function apod(): self
    {
        return $this->nextResponseFrom(self::NASA);
    }

    public function onThisDay(): self
    {
        return $this->nextResponseFrom(self::WIKIPEDIA);
    }

    public function onThisDayFrom(string $monthDay): self
    {
        $scenario = $this;

        foreach (self::ON_THIS_DAY_LISTS as $list) {
            $scenario = $scenario->onThisDay()->fixture(\sprintf(self::ON_THIS_DAY_FIXTURE, $list, $monthDay));
        }

        return $scenario;
    }

    public function forecast(): self
    {
        return $this->nextResponseFrom(self::OPEN_METEO);
    }

    public function archive(): self
    {
        return $this->nextResponseFrom(self::OPEN_METEO_ARCHIVE);
    }

    public function archiveOn(string $day): self
    {
        return $this->archive()->json(self::weatherBodyOn($day));
    }

    public function forecastOn(string $day): self
    {
        return $this->forecast()->json(self::weatherBodyOn($day));
    }

    public function json(string $body): self
    {
        return $this->withLastResponse(static function (array $response) use ($body): array {
            $response['body'] = $body;
            $response['info'][self::RESPONSE_HEADERS][self::CONTENT_TYPE] = self::JSON;

            return $response;
        });
    }

    public function fixture(string $path): self
    {
        return $this->json(self::fixtureBody($path));
    }

    public function status(int $code): self
    {
        return $this->withLastResponse(static function (array $response) use ($code): array {
            $response['info'][self::HTTP_CODE] = $code;

            return $response;
        });
    }

    public function retryAfter(int $seconds): self
    {
        return $this->withLastResponse(static function (array $response) use ($seconds): array {
            $response['info'][self::RESPONSE_HEADERS][self::RETRY_AFTER] = (string) $seconds;

            return $response;
        });
    }

    public function idleTimeout(): self
    {
        return $this->withLastResponse(static function (array $response): array {
            $response['body'] = [self::IDLE_TIMEOUT];

            return $response;
        });
    }

    public function stalled(): self
    {
        return $this->withLastResponse(static function (array $response): array {
            $response['info'][self::ERROR] = self::MAX_DURATION_REACHED;
            $response['info'][self::TOTAL_TIME] = self::MAX_DURATION_SECONDS;

            return $response;
        });
    }

    public function networkError(): self
    {
        return $this->withLastResponse(static function (array $response): array {
            $response['info'][self::ERROR] = self::CONNECTION_REFUSED;

            return $response;
        });
    }

    private static function fixtureBody(string $path): string
    {
        $body = file_get_contents(self::FIXTURES.$path);

        if (false === $body) {
            throw new \LogicException("Missing upstream fixture {$path}.");
        }

        if (json_validate($body)) {
            return $body;
        }

        throw new \LogicException("Upstream fixture {$path} is not valid JSON.");
    }

    private static function weatherBodyOn(string $day): string
    {
        /** @var array{daily: array<string, list<int>>} $body */
        $body = json_decode(self::fixtureBody(self::WEATHER_TEMPLATE), true, flags: \JSON_THROW_ON_ERROR);
        $shift = new \DateTimeImmutable($day.self::UTC_MIDNIGHT)->getTimestamp() - new \DateTimeImmutable(self::WEATHER_TEMPLATE_DAY.self::UTC_MIDNIGHT)->getTimestamp();

        foreach (self::WEATHER_TIMES as $times) {
            $body['daily'][$times] = array_map(static fn (int $time): int => $time + $shift, $body['daily'][$times]);
        }

        return json_encode($body, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<MockResponse>>
     */
    public function responses(): array
    {
        return array_map(
            static fn (array $responses): array => array_map(static fn (array $response): MockResponse => new MockResponse($response['body'], $response['info']), $responses),
            $this->responses,
        );
    }

    private function nextResponseFrom(string $upstream): self
    {
        $responses = $this->responses;
        $responses[$upstream][] = ['body' => self::EMPTY_JSON, 'info' => [self::HTTP_CODE => Response::HTTP_OK, self::RESPONSE_HEADERS => []]];

        return clone($this, ['responses' => $responses, 'upstream' => $upstream]);
    }

    /**
     * @param \Closure(array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}}): array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}} $change
     */
    private function withLastResponse(\Closure $change): self
    {
        if (null === $this->upstream) {
            throw new \LogicException(self::PICK_THE_UPSTREAM_FIRST);
        }

        $responses = $this->responses;
        $sourceResponses = $responses[$this->upstream] ?? [];
        $last = array_pop($sourceResponses);

        if (null === $last) {
            throw new \LogicException(self::PICK_THE_UPSTREAM_FIRST);
        }

        $sourceResponses[] = $change($last);
        $responses[$this->upstream] = $sourceResponses;

        return clone($this, ['responses' => $responses]);
    }
}
