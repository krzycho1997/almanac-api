<?php

declare(strict_types=1);

namespace App\Briefing\Controller;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class ClientNetworkKey
{
    public const string UNKNOWN_CLIENT = 'unknown';
    private const int IPV4_BYTES_TO_MASK = 0;
    private const int IPV6_BYTES_TO_MASK = 8;

    /**
     * @param array<string, mixed> $arguments
     */
    public static function fromRequest(array $arguments, Request $request): string
    {
        $clientIp = (string) $request->getClientIp();

        if (self::isIpAddress($clientIp)) {
            return IpUtils::anonymize($clientIp, self::IPV4_BYTES_TO_MASK, self::IPV6_BYTES_TO_MASK);
        }

        return self::UNKNOWN_CLIENT;
    }

    private static function isIpAddress(string $clientIp): bool
    {
        return false !== filter_var($clientIp, \FILTER_VALIDATE_IP);
    }
}
