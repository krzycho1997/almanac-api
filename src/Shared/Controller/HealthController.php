<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Health\HealthCheck;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    private const string STATUS = 'status';
    private const string CHECKS = 'checks';
    private const string OK = 'ok';

    /**
     * @param iterable<HealthCheck> $checks
     */
    public function __construct(
        #[AutowireIterator(HealthCheck::class)]
        private iterable $checks,
    ) {
    }

    #[Route('/api/health', name: 'shared_health', methods: [Request::METHOD_GET])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([self::STATUS => self::OK, self::CHECKS => (object) $this->reportByCheck()]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function reportByCheck(): array
    {
        $reports = [];

        foreach ($this->checks as $check) {
            $reports[$check->name()] = $check->report();
        }

        ksort($reports);

        return $reports;
    }
}
