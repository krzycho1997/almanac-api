<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;

final readonly class BriefingResultBuilder
{
    public const string DATE = '2026-11-05';
    public const string CHECKED_AT = '2026-11-18T12:00:00+01:00';
    public const float LATITUDE = 52.23;
    public const float LONGITUDE = 21.01;
    private const string TIMEZONE = 'Europe/Warsaw';

    /**
     * @param array<value-of<SourceName>, SourceResult> $results
     */
    private function __construct(
        private array $results,
        private string $checkedAt,
    ) {
    }

    public static function create(): self
    {
        return new self([], self::CHECKED_AT)
            ->ok(SourceName::Apod, SourcePayloads::apodPicture())
            ->ok(SourceName::OnThisDay, SourcePayloads::onThisDayFeed())
            ->ok(SourceName::Weather, SourcePayloads::dailyWeather());
    }

    public function ok(SourceName $source, SourcePayload $payload): self
    {
        return $this->with(SourceResult::ok($source, $payload));
    }

    public function failed(SourceName $source, FailureKind $kind): self
    {
        return $this->with(SourceResult::failed($source, $kind));
    }

    public function notApplicable(SourceName $source): self
    {
        return $this->with(SourceResult::notApplicable($source));
    }

    public function checkedAt(string $checkedAt): self
    {
        return clone($this, ['checkedAt' => $checkedAt]);
    }

    public function with(SourceResult $result): self
    {
        return clone($this, ['results' => [...$this->results, $result->source->value => $result]]);
    }

    public function build(): BriefingResult
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        return new BriefingResult(
            new \DateTimeImmutable(self::DATE, $timezone),
            Location::fromCoordinates(self::LATITUDE, self::LONGITUDE),
            array_values($this->results),
            new \DateTimeImmutable($this->checkedAt),
        );
    }
}
