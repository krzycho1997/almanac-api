<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Source\SettledFetch;
use App\Shared\Metrics\Metric;
use App\Shared\Metrics\MetricLabel;
use App\Shared\Metrics\Metrics;

final readonly class BriefingMetrics
{
    private const string OK = 'ok';
    private const string STALE = 'stale';
    private const string MISSING = 'missing';
    private const string NOT_APPLICABLE = 'not_applicable';
    private const string PAST = 'past';
    private const string TODAY = 'today';
    private const string FUTURE = 'future';
    private const string FETCH = 'fetch';
    private const string WAIT = 'wait';
    private const string FRESH = 'fresh';
    private const string FINAL = 'final';
    private const int MILLISECONDS = 1000;

    public function __construct(
        private Metrics $metrics,
    ) {
    }

    public function decided(SourceName $source, RefreshDecision $decision, \DateTimeImmutable $date, \DateTimeImmutable $now): void
    {
        $this->metrics->increment(Metric::SnapshotDecisions, [
            MetricLabel::Source->value => $source->value,
            MetricLabel::Decision->value => self::decisionOf($decision),
            MetricLabel::Day->value => self::dayOf($date, $now),
        ]);
    }

    public function fetched(SettledFetch $fetch): void
    {
        $source = $fetch->result->source->value;

        $this->metrics->increment(Metric::UpstreamFetches, [MetricLabel::Source->value => $source, MetricLabel::Result->value => self::resultOf($fetch->result)]);
        $this->metrics->observe(Metric::UpstreamFetchDuration, $fetch->durationMs / self::MILLISECONDS, [MetricLabel::Source->value => $source]);
    }

    public function served(BriefingResult $briefing): void
    {
        foreach (SourceName::cases() as $source) {
            $this->metrics->increment(Metric::BriefingSourceStatus, [MetricLabel::Source->value => $source->value, MetricLabel::Status->value => self::statusOf($briefing->entryFor($source))]);
        }
    }

    private static function decisionOf(RefreshDecision $decision): string
    {
        return match ($decision) {
            RefreshDecision::Fetch => self::FETCH,
            RefreshDecision::Wait => self::WAIT,
            RefreshDecision::Fresh => self::FRESH,
            RefreshDecision::Final => self::FINAL,
        };
    }

    private static function dayOf(\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        if (BriefingDay::isBefore($date, $now)) {
            return self::PAST;
        }

        if (BriefingDay::isSame($date, $now)) {
            return self::TODAY;
        }

        return self::FUTURE;
    }

    private static function resultOf(SourceResult $result): string
    {
        return $result->failure?->kind->value ?? self::OK;
    }

    private static function statusOf(SourceEntry $entry): string
    {
        if ($entry->hasData()) {
            return self::freshnessOf($entry);
        }

        if ($entry->hasFailed()) {
            return self::MISSING;
        }

        return self::NOT_APPLICABLE;
    }

    private static function freshnessOf(SourceEntry $entry): string
    {
        if ($entry->hasFailed()) {
            return self::STALE;
        }

        return self::OK;
    }
}
