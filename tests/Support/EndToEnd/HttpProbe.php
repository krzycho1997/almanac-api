<?php

declare(strict_types=1);

namespace App\Tests\Support\EndToEnd;

use Symfony\Component\HttpFoundation\Request;

final readonly class HttpProbe
{
    private const string BASE_URL_VARIABLE = 'E2E_BASE_URL';

    public function __construct(
        private string $baseUrl,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = getenv(self::BASE_URL_VARIABLE);

        if (\is_string($baseUrl)) {
            return new self($baseUrl);
        }

        throw new \LogicException(self::BASE_URL_VARIABLE.' is not set.');
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ProbedResponse
    {
        file_get_contents($this->baseUrl.$path, false, self::context($headers));

        return ProbedResponse::fromRawHeaders(http_get_last_response_headers() ?? []);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return resource
     */
    private static function context(array $headers)
    {
        return stream_context_create(['http' => [
            'method' => Request::METHOD_GET,
            'header' => self::headerLines($headers),
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        return array_map(static fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers);
    }
}
