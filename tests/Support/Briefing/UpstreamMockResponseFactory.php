<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

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
     * @param list<array{upstream: string, baseUri: string}> $routes
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
        $upstream = $this->upstreamFor($url);
        $this->pending[$upstream] ??= [];
        $response = array_shift($this->pending[$upstream]);

        if ($response instanceof MockResponse) {
            $this->served[] = $response;

            return $response;
        }

        throw new \LogicException("No response planned for the request to {$upstream}: {$method} {$url}");
    }

    /**
     * @return list<MockResponse>
     */
    public function served(): array
    {
        return $this->served;
    }

    /**
     * @return array<string, int>
     */
    public function unserved(): array
    {
        return array_filter(array_map(\count(...), $this->pending), static fn (int $left): bool => $left > 0);
    }

    private function upstreamFor(string $url): string
    {
        foreach ($this->routes as $route) {
            if (str_starts_with($url, $route['baseUri'])) {
                return $route['upstream'];
            }
        }

        throw new \LogicException("Request to an unknown upstream: {$url}");
    }
}
