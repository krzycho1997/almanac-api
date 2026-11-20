<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

final class ProblemJsonErrorRendererTest extends TestCase
{
    private const string HTML = 'text/html; charset=UTF-8';
    private const string TEXT = 'text/plain';

    #[DataProvider('renderedErrors')]
    public function testMarksOnlyProblemDocumentsAsProblemJson(string $contentType, string $body, string $expectedContentType): void
    {
        // Given
        $inner = self::createStub(ErrorRendererInterface::class);
        $inner->method('render')->willReturn(
            FlattenException::create(new \RuntimeException(), 404, ['Content-Type' => $contentType, 'Vary' => 'Accept'])->setAsString($body),
        );
        $renderer = new ProblemJsonErrorRenderer($inner);

        // When
        $headers = $renderer->render(new \RuntimeException())->getHeaders();

        // Then
        self::assertSame($expectedContentType, $headers['Content-Type']);
        self::assertSame('Accept', $headers['Vary']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function renderedErrors(): iterable
    {
        yield 'problem document' => [ProblemJsonErrorRenderer::JSON, '{"type":"about:blank","title":"Not Found","status":404}', ProblemJsonErrorRenderer::PROBLEM_JSON];
        yield 'JSON without problem members' => [ProblemJsonErrorRenderer::JSON, '{"message":"Not Found"}', ProblemJsonErrorRenderer::JSON];
        yield 'without a type' => [ProblemJsonErrorRenderer::JSON, '{"title":"Not Found","status":404}', ProblemJsonErrorRenderer::JSON];
        yield 'without a title' => [ProblemJsonErrorRenderer::JSON, '{"type":"about:blank","status":404}', ProblemJsonErrorRenderer::JSON];
        yield 'without a status' => [ProblemJsonErrorRenderer::JSON, '{"type":"about:blank","title":"Not Found"}', ProblemJsonErrorRenderer::JSON];
        yield 'problem members in plain text' => [self::TEXT, '{"type":"about:blank","title":"Not Found","status":404}', self::TEXT];
        yield 'JSON scalar' => [ProblemJsonErrorRenderer::JSON, '1', ProblemJsonErrorRenderer::JSON];
        yield 'JSON list' => [ProblemJsonErrorRenderer::JSON, '[]', ProblemJsonErrorRenderer::JSON];
        yield 'invalid JSON' => [ProblemJsonErrorRenderer::JSON, '{', ProblemJsonErrorRenderer::JSON];
        yield 'HTML fallback' => [self::HTML, '<html></html>', self::HTML];
    }
}
