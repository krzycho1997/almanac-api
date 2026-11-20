<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StreamedProblemController
{
    public const string PATH = '/_test/streamed-problem';
    public const string BODY = '{"type":"about:blank","title":"Gone","status":410,"detail":"Gone"}';

    #[Route(self::PATH, name: 'test_streamed_problem', methods: [Request::METHOD_GET])]
    public function __invoke(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo self::BODY;
        }, Response::HTTP_GONE, ['Content-Type' => ProblemJsonErrorRenderer::PROBLEM_JSON]);
    }
}
