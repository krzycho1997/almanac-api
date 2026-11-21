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
        return $this->request(Request::METHOD_GET, $path, $headers, '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $path, string $content, array $headers = []): ProbedResponse
    {
        return $this->request(Request::METHOD_POST, $path, $headers, $content);
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $headers = [], string $content = ''): ProbedResponse
    {
        $body = file_get_contents($this->baseUrl.$path, false, self::context($method, $headers, $content));

        return ProbedResponse::fromRawHeaders(http_get_last_response_headers() ?? [], \is_string($body) ? $body : '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return resource
     */
    private static function context(string $method, array $headers, string $content)
    {
        return stream_context_create(['http' => [
            'method' => $method,
            'header' => self::headerLines($headers),
            'content' => $content,
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
