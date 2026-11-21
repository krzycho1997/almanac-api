<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

enum Metric: string
{
    case HttpRequests = 'http_requests_total';
    case HttpRequestDuration = 'http_request_duration_seconds';
    case SnapshotDecisions = 'snapshot_decisions_total';
    case UpstreamFetches = 'upstream_fetches_total';
    case UpstreamFetchDuration = 'upstream_fetch_duration_seconds';
    case BriefingSourceStatus = 'briefing_source_status_total';
    case CircuitTransitions = 'circuit_transitions_total';
    case MessengerFailedMessages = 'messenger_failed_messages';

    private const array HTTP_BUCKETS = [0.025, 0.05, 0.1, 0.25, 0.5, 1, 2, 4, 9, 30];
    private const array UPSTREAM_BUCKETS = [0.1, 0.25, 0.5, 1, 1.5, 2, 3, 5, 8, 8.5];

    public function help(): string
    {
        return match ($this) {
            self::HttpRequests => 'HTTP requests answered, by route, method and status code',
            self::HttpRequestDuration => 'Time from the start of a request until its response is sent, by route',
            self::SnapshotDecisions => 'Refresh decisions for stored snapshots, by source, decision and day',
            self::UpstreamFetches => 'Upstream fetches, by source and result',
            self::UpstreamFetchDuration => 'Time of an upstream fetch, retries included, by source',
            self::BriefingSourceStatus => 'Sources served in briefings, by source and status',
            self::CircuitTransitions => 'Circuit breaker transitions, by source and new state',
            self::MessengerFailedMessages => 'Messages waiting in the failed transport, read at scrape time',
        };
    }

    /**
     * @return list<MetricLabel>
     */
    public function labels(): array
    {
        return match ($this) {
            self::HttpRequests => [MetricLabel::Route, MetricLabel::Method, MetricLabel::Code],
            self::HttpRequestDuration => [MetricLabel::Route],
            self::SnapshotDecisions => [MetricLabel::Source, MetricLabel::Decision, MetricLabel::Day],
            self::UpstreamFetches => [MetricLabel::Source, MetricLabel::Result],
            self::UpstreamFetchDuration => [MetricLabel::Source],
            self::BriefingSourceStatus => [MetricLabel::Source, MetricLabel::Status],
            self::CircuitTransitions => [MetricLabel::Source, MetricLabel::To],
            self::MessengerFailedMessages => [],
        };
    }

    /**
     * @return list<string>
     */
    public function labelNames(): array
    {
        return array_map(static fn (MetricLabel $label): string => $label->value, $this->labels());
    }

    /**
     * @return list<float|int>
     */
    public function buckets(): array
    {
        return match ($this) {
            self::HttpRequestDuration => self::HTTP_BUCKETS,
            self::UpstreamFetchDuration => self::UPSTREAM_BUCKETS,
            default => [],
        };
    }
}
