<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class ScriptedUpstream implements HttpClientInterface
{
    private const float NO_WAIT = 0.0;

    /**
     * @var list<array{float, ResponseInterface, ChunkInterface}>
     */
    private array $script = [];

    /**
     * @var list<float>
     */
    private array $timeouts = [];

    /**
     * @var list<int>
     */
    private array $streamedCounts = [];

    private ?\Throwable $failure = null;

    private ?\Throwable $breakage = null;

    public function __construct(
        private readonly MockClock $clock,
    ) {
    }

    public function after(float $seconds, ResponseInterface $response, ChunkInterface $chunk): self
    {
        $this->script[] = [$seconds, $response, $chunk];

        return $this;
    }

    public function failingWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function breakingWith(\Throwable $breakage): self
    {
        $this->breakage = $breakage;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function streamedCounts(): array
    {
        return $this->streamedCounts;
    }

    /**
     * @return list<float>
     */
    public function timeouts(): array
    {
        return $this->timeouts;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        throw new \LogicException('The scripted upstream only streams responses started elsewhere.');
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->timeouts[] = $timeout ?? self::NO_WAIT;
        $this->streamedCounts[] = \count(self::listed($responses));

        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new ResponseStream($this->play($timeout ?? self::NO_WAIT));
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }

    /**
     * @param ResponseInterface|iterable<ResponseInterface> $responses
     *
     * @return list<ResponseInterface>
     */
    private static function listed(ResponseInterface|iterable $responses): array
    {
        if ($responses instanceof ResponseInterface) {
            return [$responses];
        }

        return array_values([...$responses]);
    }

    /**
     * @return \Generator<ResponseInterface, ChunkInterface>
     */
    private function play(float $timeout): \Generator
    {
        if ($this->breakage instanceof \Throwable) {
            throw $this->breakage;
        }

        while ([] !== $this->script) {
            [$wait, $response, $chunk] = $this->script[0];

            if ($wait > $timeout) {
                $this->clock->sleep($timeout);
                $this->script[0][0] = $wait - $timeout;

                return;
            }

            $this->clock->sleep($wait);
            array_shift($this->script);

            yield $response => $chunk;
        }

        $this->clock->sleep($timeout);
    }
}
