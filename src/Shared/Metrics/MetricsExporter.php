<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Prometheus\RenderTextFormat;

interface MetricsExporter
{
    public const string CONTENT_TYPE = RenderTextFormat::MIME_TYPE.'; charset=UTF-8';

    public function render(): string;
}
