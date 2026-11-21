<?php

declare(strict_types=1);

namespace App\Shared\ErrorHandler;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[AsDecorator('serializer.normalizer.problem')]
final readonly class ProblemDetailsNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    public const string BLANK = 'about:blank';
    private const string OBSOLETE_TYPE = 'https://tools.ietf.org/html/rfc2616#section-10';
    private const string DETAIL = 'detail';
    private const string UNEXPECTED = 'The server could not complete the request; quote the X-Request-Id header when reporting it.';
    private const string APPLICATION = 'App\\';
    private const array DETAILS = [
        Response::HTTP_BAD_REQUEST => 'The request could not be read, for example a body that is not valid JSON.',
        Response::HTTP_UNAUTHORIZED => 'Send a valid access token as "Authorization: Bearer <token>".',
        Response::HTTP_NOT_FOUND => 'Nothing exists at this address.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'This address does not accept the method; the Allow header lists the ones it does.',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'Send the body as application/json.',
        Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests from this network; try again after the number of seconds in Retry-After.',
    ];

    public function __construct(
        #[AutowireDecorated]
        private NormalizerInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $problem = $this->inner->normalize($data, $format, $context);
        \assert(\is_array($problem) && $data instanceof FlattenException);

        if (self::OBSOLETE_TYPE === $problem[ProblemNormalizer::TYPE]) {
            return self::named($problem, $data);
        }

        return $problem;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->inner instanceof SerializerAwareInterface) {
            $this->inner->setSerializer($serializer);
        }
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /**
     * @param array<string, mixed> $problem
     *
     * @return array<string, mixed>
     */
    private static function named(array $problem, FlattenException $exception): array
    {
        return [
            ProblemNormalizer::TYPE => self::BLANK,
            ProblemNormalizer::TITLE => $exception->getStatusText(),
            ProblemNormalizer::STATUS => $problem[ProblemNormalizer::STATUS],
            self::DETAIL => self::detailOf($exception),
        ] + $problem;
    }

    private static function detailOf(FlattenException $exception): string
    {
        if (Response::HTTP_INTERNAL_SERVER_ERROR === $exception->getStatusCode()) {
            return self::UNEXPECTED;
        }

        if (self::isThrownByTheApplication($exception)) {
            return $exception->getMessage();
        }

        return self::DETAILS[$exception->getStatusCode()] ?? $exception->getStatusText();
    }

    private static function isThrownByTheApplication(FlattenException $exception): bool
    {
        if (str_starts_with($exception->getClass(), self::APPLICATION)) {
            return true;
        }

        return str_starts_with((string) $exception->getPrevious()?->getClass(), self::APPLICATION);
    }
}
