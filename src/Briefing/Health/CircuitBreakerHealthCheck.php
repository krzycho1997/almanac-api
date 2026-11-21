<?php

declare(strict_types=1);

namespace App\Briefing\Health;

use App\Briefing\CircuitBreaker\CircuitBreaker;
use App\Briefing\CircuitBreaker\CircuitState;
use App\Briefing\Dto\Response\CircuitStateValue;
use App\Briefing\Mapper\SourceKeyMapper;
use App\Briefing\SourceName;
use App\Shared\Health\HealthCheck;

final readonly class CircuitBreakerHealthCheck implements HealthCheck
{
    private const string NAME = 'circuits';

    public function __construct(private CircuitBreaker $breaker)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function report(): array
    {
        $report = [];

        foreach (SourceName::cases() as $source) {
            $report[SourceKeyMapper::keyOf($source)->value] = self::valueOf($this->breaker->state($source))->value;
        }

        return $report;
    }

    private static function valueOf(CircuitState $state): CircuitStateValue
    {
        return match ($state) {
            CircuitState::Closed => CircuitStateValue::Closed,
            CircuitState::Open => CircuitStateValue::Open,
            CircuitState::HalfOpen => CircuitStateValue::HalfOpen,
        };
    }
}
