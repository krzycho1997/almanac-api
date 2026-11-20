<?php

declare(strict_types=1);

namespace App\Briefing\Source;

final class WebAddress
{
    private const array WEB_SCHEMES = ['http', 'https'];

    public static function isUsable(string $url): bool
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        return \in_array(parse_url($url, \PHP_URL_SCHEME), self::WEB_SCHEMES, true);
    }
}
