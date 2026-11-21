<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Briefing\Exception\NoSourceDataException;
use App\Shared\ErrorHandler\ProblemDetailsNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;

final class ProblemDetailsNormalizerTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('problems')]
    public function testNamesTheProblemByItsStatus(\Throwable $exception, int $status, array $expected): void
    {
        // Given
        $normalizer = new ProblemDetailsNormalizer(new ProblemNormalizer(false));

        // When
        $problem = $normalizer->normalize(FlattenException::createFromThrowable($exception, $status));

        // Then
        self::assertSame($expected, $problem);
    }

    /**
     * @return iterable<string, array{\Throwable, int, array<string, mixed>}>
     */
    public static function problems(): iterable
    {
        yield 'the message of an application exception' => [NoSourceDataException::forBriefing(), 502, ['type' => 'about:blank', 'title' => 'Bad Gateway', 'status' => 502, 'detail' => 'No source has data for this briefing.']];
        yield 'the message of an application exception mapped to a status' => [new HttpException(502, 'No source has data for this briefing.', NoSourceDataException::forBriefing()), 502, ['type' => 'about:blank', 'title' => 'Bad Gateway', 'status' => 502, 'detail' => 'No source has data for this briefing.']];
        yield 'a fixed sentence instead of a framework message' => [new NotFoundHttpException('"App\Entity\Bookmark" object not found by "EntityValueResolver".'), 404, ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'Nothing exists at this address.']];
        yield 'nothing of an unexpected failure' => [new \RuntimeException('SQLSTATE[08006] connection to server failed'), 500, ['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500, 'detail' => 'The server could not complete the request; quote the X-Request-Id header when reporting it.']];
        yield 'the status text where no sentence fits' => [new \RuntimeException('teapot internals'), 418, ['type' => 'about:blank', 'title' => "I'm a teapot", 'status' => 418, 'detail' => "I'm a teapot"]];
    }
}
