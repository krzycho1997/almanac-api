<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Metrics\MetricsExporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MetricsController
{
    public const string PATH = '/metrics';
    public const string SCRAPE_PARAMETER = 'ALMANAC_METRICS_SCRAPE';
    private const string SCRAPE = '1';
    private const string CONTENT_TYPE = 'Content-Type';

    public function __construct(
        private MetricsExporter $exporter,
    ) {
    }

    #[Route(self::PATH, name: 'shared_metrics', methods: [Request::METHOD_GET])]
    public function __invoke(Request $request): Response
    {
        if (self::SCRAPE === $request->server->get(self::SCRAPE_PARAMETER)) {
            return new Response($this->exporter->render(), Response::HTTP_OK, [self::CONTENT_TYPE => MetricsExporter::CONTENT_TYPE]);
        }

        throw new NotFoundHttpException();
    }
}
