<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\SourceName;
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
    private const string FIXTURES = __DIR__.'/../../Fixtures/upstream/';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string JSON = 'application/json';
    private const array ON_THIS_DAY_LISTS = ['selected', 'births', 'deaths'];
    private const string ON_THIS_DAY_FIXTURE = 'on_this_day/%s-%s.json';
    private const string PICK_THE_UPSTREAM_FIRST = 'Pick the upstream first, e.g. UpstreamMock::create()->apod().';

    /**
     * @param array<string, list<array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}}>> $responses
     */
    private function __construct(
        private array $responses,
        private ?SourceName $source,
    ) {
    }

    public static function create(): self
    {
        return new self([], null);
    }

    public function apod(): self
    {
        return $this->nextResponseFrom(SourceName::Apod);
    }

    public function onThisDay(): self
    {
        return $this->nextResponseFrom(SourceName::OnThisDay);
    }

    public function onThisDayFrom(string $monthDay): self
    {
        $scenario = $this;

        foreach (self::ON_THIS_DAY_LISTS as $list) {
            $scenario = $scenario->onThisDay()->fixture(\sprintf(self::ON_THIS_DAY_FIXTURE, $list, $monthDay));
        }

        return $scenario;
    }

    public function weather(): self
    {
        return $this->nextResponseFrom(SourceName::Weather);
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
        $body = file_get_contents(self::FIXTURES.$path);

        if (false === $body) {
            throw new \LogicException("Missing upstream fixture {$path}.");
        }

        if (json_validate($body)) {
            return $this->json($body);
        }

        throw new \LogicException("Upstream fixture {$path} is not valid JSON.");
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

    public function timeout(): self
    {
        return $this->withLastResponse(static function (array $response): array {
            $response['body'] = [self::IDLE_TIMEOUT];

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

    private function nextResponseFrom(SourceName $source): self
    {
        $responses = $this->responses;
        $responses[$source->value][] = ['body' => self::EMPTY_JSON, 'info' => [self::HTTP_CODE => Response::HTTP_OK, self::RESPONSE_HEADERS => []]];

        return clone($this, ['responses' => $responses, 'source' => $source]);
    }

    /**
     * @param \Closure(array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}}): array{body: string|list<string>, info: array{http_code: int, response_headers: array<string, string>, error?: string}} $change
     */
    private function withLastResponse(\Closure $change): self
    {
        if (null === $this->source) {
            throw new \LogicException(self::PICK_THE_UPSTREAM_FIRST);
        }

        $responses = $this->responses;
        $sourceResponses = $responses[$this->source->value] ?? [];
        $last = array_pop($sourceResponses);

        if (null === $last) {
            throw new \LogicException(self::PICK_THE_UPSTREAM_FIRST);
        }

        $sourceResponses[] = $change($last);
        $responses[$this->source->value] = $sourceResponses;

        return clone($this, ['responses' => $responses]);
    }
}
