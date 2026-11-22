<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

final class ProbeBuildsAddresses
{
    public function pageOf(string $host, string $title): string
    {
        return "https://{$host}/wiki/{$title}";
    }

    public function itemOf(string $id): string
    {
        return <<<TEXT
            https://www.wikidata.org/wiki/{$id}
            TEXT;
    }

    public function forecast(): string
    {
        return <<<'TEXT'
            https://API.OPEN-METEO.COM/v1/forecast
            TEXT;
    }
}
