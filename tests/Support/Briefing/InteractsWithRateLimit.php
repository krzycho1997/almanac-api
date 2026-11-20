<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Controller\ClientNetworkKey;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithRateLimit
{
    protected const string CLIENT_IP = '127.0.0.1';

    protected static function spendBriefingRequests(int $requests, string $clientIp = self::CLIENT_IP): void
    {
        self::assertTrue(self::briefingLimiterFor($clientIp)->consume($requests)->isAccepted());
    }

    protected static function briefingRequestsLeft(string $clientIp = self::CLIENT_IP): int
    {
        return self::briefingLimiterFor($clientIp)->consume(0)->getRemainingTokens();
    }

    private static function briefingLimiterFor(string $clientIp): LimiterInterface
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => $clientIp]);

        return self::getContainer()->get(BriefingRateLimit::LIMITER)->create(ClientNetworkKey::fromRequest([], $request));
    }
}
