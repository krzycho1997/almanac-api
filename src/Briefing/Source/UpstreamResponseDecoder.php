<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class UpstreamResponseDecoder
{
    private const array CONTEXT = [
        AbstractNormalizer::GROUPS => ['*'],
        DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
    ];

    public function __construct(
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function decode(ResponseInterface $response, string $type): object
    {
        $decoded = $this->deserialized($response->getContent(), $type);
        $this->assertValid($decoded, $type);

        return $decoded;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function deserialized(string $body, string $type): object
    {
        try {
            $decoded = $this->serializer->deserialize($body, $type, JsonEncoder::FORMAT, self::CONTEXT);
        } catch (NotEncodableValueException) {
            throw InvalidUpstreamPayloadException::notJson($type);
        } catch (PartialDenormalizationException $exception) {
            throw InvalidUpstreamPayloadException::doesNotMatch($type, self::failedPaths($exception));
        } catch (MissingConstructorArgumentsException|UnexpectedValueException) {
            throw InvalidUpstreamPayloadException::doesNotMatch($type, []);
        }

        if ($decoded instanceof $type) {
            return $decoded;
        }

        throw InvalidUpstreamPayloadException::doesNotMatch($type, []);
    }

    /**
     * @return list<string>
     */
    private static function failedPaths(PartialDenormalizationException $exception): array
    {
        $paths = [];

        foreach ($exception->getNotNormalizableValueErrors() as $error) {
            $paths[] = $error->getPath();
        }

        return array_values(array_filter($paths, \is_string(...)));
    }

    private function assertValid(object $decoded, string $type): void
    {
        $violations = $this->validator->validate($decoded);

        if (0 === $violations->count()) {
            return;
        }

        throw InvalidUpstreamPayloadException::isInvalidAt($type, self::violatedPaths($violations));
    }

    /**
     * @return list<string>
     */
    private static function violatedPaths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];

        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
