<?php

declare(strict_types=1);

namespace App\Briefing\Source;

final class WebAddress
{
    private const string SECURE_SCHEME = 'https';

    public static function isUsable(string $url): bool
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        return self::SECURE_SCHEME === parse_url($url, \PHP_URL_SCHEME);
    }
}
