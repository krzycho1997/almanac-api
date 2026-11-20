<?php

declare(strict_types=1);

namespace App\Shared\ErrorHandler;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;

#[AsDecorator('error_renderer')]
final readonly class ProblemJsonErrorRenderer implements ErrorRendererInterface
{
    private const string CONTENT_TYPE = 'Content-Type';
    public const string JSON = 'application/json';
    public const string PROBLEM_JSON = 'application/problem+json';

    public function __construct(
        #[AutowireDecorated]
        private ErrorRendererInterface $inner,
    ) {
    }

    public function render(\Throwable $exception): FlattenException
    {
        $flattenException = $this->inner->render($exception);

        if (self::isProblemDocument($flattenException)) {
            return self::markAsProblem($flattenException);
        }

        return $flattenException;
    }

    private static function isProblemDocument(FlattenException $flattenException): bool
    {
        if (self::JSON === ($flattenException->getHeaders()[self::CONTENT_TYPE] ?? null)) {
            return self::hasProblemMembers($flattenException->getAsString());
        }

        return false;
    }

    private static function hasProblemMembers(string $body): bool
    {
        try {
            $problem = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (\is_array($problem)) {
            return isset($problem[ProblemNormalizer::TYPE], $problem[ProblemNormalizer::TITLE], $problem[ProblemNormalizer::STATUS]);
        }

        return false;
    }

    private static function markAsProblem(FlattenException $flattenException): FlattenException
    {
        return $flattenException->setHeaders([self::CONTENT_TYPE => self::PROBLEM_JSON] + $flattenException->getHeaders());
    }
}
