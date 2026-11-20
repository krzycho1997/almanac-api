<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

return App::config([
    'framework' => [
        'serializer' => [
            'default_context' => [
                JsonEncode::OPTIONS => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
                DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\TH:i:sp',
                DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
            ],
        ],
    ],
]);
