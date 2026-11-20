<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

final class DelayedUpstream
{
    private const string SCRIPT = __DIR__.'/delayed-upstream.php';
    private const string PHP = 'php';
    private const string PIPE = 'pipe';
    private const string WRITE = 'w';
    private const string SCHEME = 'http://';

    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly string $baseUri,
    ) {
    }

    public static function start(): self
    {
        $process = proc_open([self::PHP, self::SCRIPT], [1 => [self::PIPE, self::WRITE], 2 => [self::PIPE, self::WRITE]], $pipes);

        if (false === $process) {
            throw new \RuntimeException('The delayed upstream could not start.');
        }

        return new self($process, self::SCHEME.trim((string) fgets($pipes[1])).'/');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
