<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Contracts\HttpClient\ResponseInterface;

final class StreamProgress
{
    /**
     * @var \WeakMap<ResponseInterface, \DateTimeImmutable>
     */
    private \WeakMap $finished;

    /**
     * @var \WeakMap<ResponseInterface, \DateTimeImmutable>
     */
    private \WeakMap $failed;

    public function __construct()
    {
        $this->finished = new \WeakMap();
        $this->failed = new \WeakMap();
    }

    public function finish(ResponseInterface $response, \DateTimeImmutable $at): void
    {
        $this->finished[$response] = $at;
    }

    public function fail(ResponseInterface $response, \DateTimeImmutable $at): void
    {
        $this->failed[$response] = $at;
        $this->finished[$response] = $at;
    }

    public function settledAt(SourceFetch $fetch): ?\DateTimeImmutable
    {
        return $this->firstFailureOf($fetch) ?? $this->lastFinishOf($fetch);
    }

    /**
     * @param list<SourceFetch> $fetches
     *
     * @return list<ResponseInterface>
     */
    public function unfinishedOf(array $fetches): array
    {
        $unsettled = array_filter($fetches, fn (SourceFetch $fetch): bool => null === $this->settledAt($fetch));

        return array_values(array_filter(self::responsesOf($unsettled), fn (ResponseInterface $response): bool => false === isset($this->finished[$response])));
    }

    public function cancelUnfinished(SourceFetch $fetch): void
    {
        foreach ($fetch->pending->responses() as $response) {
            $this->cancelIfUnfinished($response);
        }
    }

    private function cancelIfUnfinished(ResponseInterface $response): void
    {
        if (isset($this->finished[$response])) {
            return;
        }

        $response->cancel();
    }

    private function firstFailureOf(SourceFetch $fetch): ?\DateTimeImmutable
    {
        $failures = array_values(array_filter(array_map(fn (ResponseInterface $response): ?\DateTimeImmutable => $this->failed[$response] ?? null, $fetch->pending->responses())));

        if ([] === $failures) {
            return null;
        }

        return min($failures);
    }

    private function lastFinishOf(SourceFetch $fetch): ?\DateTimeImmutable
    {
        $finishes = [];

        foreach ($fetch->pending->responses() as $response) {
            if (isset($this->finished[$response])) {
                $finishes[] = $this->finished[$response];

                continue;
            }

            return null;
        }

        return max($finishes);
    }

    /**
     * @param array<SourceFetch> $fetches
     *
     * @return list<ResponseInterface>
     */
    private static function responsesOf(array $fetches): array
    {
        return array_merge(...array_values(array_map(static fn (SourceFetch $fetch): array => $fetch->pending->responses(), $fetches)));
    }
}
