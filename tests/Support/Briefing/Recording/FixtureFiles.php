<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

final readonly class FixtureFiles
{
    public const string RECORDED = 'recorded.json';
    private const string UPSTREAM = __DIR__.'/../../../Fixtures/upstream/';
    private const string BODIES = '*/*.json';
    private const string SEPARATOR = '/';
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;
    private const string NEWLINE = "\n";
    private const string DAY = 'Y-m-d';
    private const string UTC = 'UTC';
    private const string NOW = 'now';
    private const string DATE_HEADER = 'date';
    private const array KEPT_HEADERS = ['content-type', self::DATE_HEADER, 'retry-after', 'x-ratelimit-limit', 'x-ratelimit-window'];
    private const array VOLATILE_FIELDS = ['generationtime_ms'];
    private const string HEADER_SEPARATOR = ', ';
    private const string URL = 'url';
    private const string STATUS = 'status';
    private const string RECORDED_ON = 'recordedOn';
    private const string HEADERS = 'headers';

    public function __construct(
        private string $root = self::UPSTREAM,
    ) {
    }

    public static function encode(mixed $data): string
    {
        return json_encode($data, self::JSON_FLAGS).self::NEWLINE;
    }

    public static function canonical(mixed $body): string
    {
        return self::encode(self::sorted(self::withoutVolatileFields($body)));
    }

    public function contents(string $path): string
    {
        $contents = @file_get_contents($this->root.$path);

        if (\is_string($contents)) {
            return $contents;
        }

        throw new \RuntimeException("The fixture {$path} does not exist.");
    }

    public function body(UpstreamFixture $fixture): mixed
    {
        return json_decode($this->contents($fixture->path), false, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public function record(UpstreamFixture $fixture, mixed $body, int $status, array $headers): bool
    {
        if ($this->hasDrifted($fixture, $body)) {
            file_put_contents($this->root.$fixture->path, self::encode($body));
            $this->remember($fixture, $status, $headers);

            return true;
        }

        return false;
    }

    public function hasDrifted(UpstreamFixture $fixture, mixed $body): bool
    {
        if (is_file($this->root.$fixture->path)) {
            return self::canonical($this->body($fixture)) !== self::canonical($body);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function bodies(): array
    {
        $paths = array_map(fn (string $file): string => substr($file, \strlen($this->root)), $this->files());

        return array_values(array_filter($paths, static fn (string $path): bool => self::RECORDED !== basename($path)));
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return array_values(array_unique(array_map(\dirname(...), $this->bodies())));
    }

    public function recorded(string $directory): \stdClass
    {
        $file = $this->root.$directory.self::SEPARATOR.self::RECORDED;

        if (is_file($file)) {
            $recorded = json_decode((string) file_get_contents($file), false, flags: \JSON_THROW_ON_ERROR);

            if ($recorded instanceof \stdClass) {
                return $recorded;
            }
        }

        return new \stdClass();
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->root.self::BODIES);

        if (\is_array($files)) {
            return $files;
        }

        return [];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function remember(UpstreamFixture $fixture, int $status, array $headers): void
    {
        $directory = \dirname($fixture->path);
        $entries = get_object_vars($this->recorded($directory));
        $entries[basename($fixture->path)] = (object) [
            self::URL => $fixture->url,
            self::STATUS => $status,
            self::RECORDED_ON => self::dayOf($headers),
            self::HEADERS => (object) self::keptHeaders($headers),
        ];
        ksort($entries);
        file_put_contents($this->root.$directory.self::SEPARATOR.self::RECORDED, self::encode((object) $entries));
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function dayOf(array $headers): string
    {
        return new \DateTimeImmutable($headers[self::DATE_HEADER][0] ?? self::NOW, new \DateTimeZone(self::UTC))->format(self::DAY);
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private static function keptHeaders(array $headers): array
    {
        $kept = array_intersect_key($headers, array_flip(self::KEPT_HEADERS));
        ksort($kept);

        return array_map(static fn (array $values): string => implode(self::HEADER_SEPARATOR, $values), $kept);
    }

    private static function withoutVolatileFields(mixed $body): mixed
    {
        if ($body instanceof \stdClass) {
            return (object) array_diff_key(get_object_vars($body), array_flip(self::VOLATILE_FIELDS));
        }

        return $body;
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sorted(...), $properties);
        }

        if (\is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }
}
