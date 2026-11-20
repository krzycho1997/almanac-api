<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Exception\UnreadablePayloadException;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\SourcePayload;
use App\Shared\Serializer\Moment;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SourcePayloadCodec
{
    private const string SERIALIZER = 'snapshot';
    private const array CONTEXT = [DateTimeNormalizer::FORMAT_KEY => Moment::FORMAT];

    public function __construct(
        #[Target(self::SERIALIZER)]
        private NormalizerInterface $normalizer,
        #[Target(self::SERIALIZER)]
        private DenormalizerInterface $denormalizer,
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function encode(SourcePayload $payload): array
    {
        $data = $this->normalizer->normalize($payload, JsonEncoder::FORMAT, self::CONTEXT);

        if (\is_array($data)) {
            return $data;
        }

        throw new \LogicException(\sprintf('A source payload must normalize to an array, %s normalized to %s.', $payload::class, get_debug_type($data)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function decode(SourceName $source, array $data): SourcePayload
    {
        try {
            return $this->denormalizer->denormalize($data, self::payloadClass($source), JsonEncoder::FORMAT, self::CONTEXT);
        } catch (ExceptionInterface $exception) {
            throw UnreadablePayloadException::forSource($source, $exception);
        }
    }

    /**
     * @return class-string<SourcePayload>
     */
    private static function payloadClass(SourceName $source): string
    {
        return match ($source) {
            SourceName::Apod => ApodPicture::class,
            SourceName::OnThisDay => OnThisDayFeed::class,
            SourceName::Weather => DailyWeather::class,
        };
    }
}
