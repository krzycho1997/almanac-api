<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Shared\Serializer\Moment;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

return App::config([
    'framework' => [
        'serializer' => [
            'default_context' => [
                JsonEncode::OPTIONS => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
                DateTimeNormalizer::FORMAT_KEY => Moment::FORMAT,
                DateTimeNormalizer::TIMEZONE_KEY => Moment::TIMEZONE,
            ],
            'named_serializers' => [
                'snapshot' => [
                    'default_context' => [
                        DateTimeNormalizer::TIMEZONE_KEY => Moment::TIMEZONE,
                        DateTimeNormalizer::FORCE_TIMEZONE_KEY => true,
                        AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
                        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => true,
                        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
                    ],
                ],
            ],
        ],
    ],
]);
