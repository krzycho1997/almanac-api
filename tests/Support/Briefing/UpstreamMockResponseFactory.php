<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\SourceName;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpstreamMockResponseFactory
{
    /**
     * @var array<string, list<MockResponse>>
     */
    private array $pending = [];

    /**
     * @var list<MockResponse>
     */
    private array $served = [];

    /**
     * @param list<array{source: string, baseUri: string}> $routes
     */
    public function __construct(
        private readonly array $routes,
    ) {
    }

    public function respondWith(UpstreamMock $scenario): void
    {
        $this->pending = $scenario->responses();
        $this->served = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $source = $this->sourceFor($url);
        $this->pending[$source->value] ??= [];
        $response = array_shift($this->pending[$source->value]);

        if ($response instanceof MockResponse) {
            $this->served[] = $response;

            return $response;
        }

        throw new \LogicException("No response planned for the request to {$source->value}: {$method} {$url}");
    }

    /**
     * @return list<MockResponse>
     */
    public function served(): array
    {
        return $this->served;
    }

    private function sourceFor(string $url): SourceName
    {
        foreach ($this->routes as $route) {
            if (str_starts_with($url, $route['baseUri'])) {
                return SourceName::from($route['source']);
            }
        }

        throw new \LogicException("Request to an unknown upstream: {$url}");
    }
}
