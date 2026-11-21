<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SourceEntry;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;

final readonly class BriefingResultBuilder
{
    public const string DATE = '2026-11-05';
    public const string ATTEMPTED_AT = '2026-11-18T12:00:00+01:00';
    public const float LATITUDE = 52.23;
    public const float LONGITUDE = 21.01;
    private const string TIMEZONE = 'Europe/Warsaw';

    /**
     * @param array<value-of<SourceName>, \Closure(\DateTimeImmutable): SourceEntry> $entries
     */
    private function __construct(
        private array $entries,
        private string $attemptedAt,
    ) {
    }

    public static function create(): self
    {
        return new self([], self::ATTEMPTED_AT)
            ->ok(SourceName::Apod, SourcePayloads::apodPicture())
            ->ok(SourceName::OnThisDay, SourcePayloads::onThisDayFeed())
            ->ok(SourceName::Weather, SourcePayloads::dailyWeather());
    }

    public function ok(SourceName $source, SourcePayload $payload): self
    {
        return $this->with($source, static fn (\DateTimeImmutable $attemptedAt): SourceEntry => SourceEntry::fresh($source, $payload, $attemptedAt));
    }

    public function stale(SourceName $source, SourcePayload $payload, string $fetchedAt, FailureKind $kind): self
    {
        return $this->with($source, static fn (\DateTimeImmutable $attemptedAt): SourceEntry => SourceEntry::stale($source, $payload, new \DateTimeImmutable($fetchedAt), new SourceFailure($kind), $attemptedAt));
    }

    public function failed(SourceName $source, FailureKind $kind): self
    {
        return $this->with($source, static fn (\DateTimeImmutable $attemptedAt): SourceEntry => SourceEntry::missing($source, new SourceFailure($kind), $attemptedAt));
    }

    public function notApplicable(SourceName $source): self
    {
        return $this->with($source, static fn (): SourceEntry => SourceEntry::notApplicable($source));
    }

    public function attemptedAt(string $attemptedAt): self
    {
        return clone($this, ['attemptedAt' => $attemptedAt]);
    }

    public function build(): BriefingResult
    {
        $attemptedAt = new \DateTimeImmutable($this->attemptedAt);

        return new BriefingResult(
            new \DateTimeImmutable(self::DATE, new \DateTimeZone(self::TIMEZONE)),
            Location::fromCoordinates(self::LATITUDE, self::LONGITUDE),
            array_values(array_map(static fn (\Closure $entry): SourceEntry => $entry($attemptedAt), $this->entries)),
            [],
        );
    }

    /**
     * @param \Closure(\DateTimeImmutable): SourceEntry $entry
     */
    private function with(SourceName $source, \Closure $entry): self
    {
        return clone($this, ['entries' => [...$this->entries, $source->value => $entry]]);
    }
}
