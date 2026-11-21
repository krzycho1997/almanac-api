<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final readonly class OpenApiController
{
    private const int ENCODING = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;
    private const int CACHE_SECONDS = 300;
    private const int PARSING = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;

    public function __construct(
        #[Autowire('%kernel.project_dir%/docs/openapi.yaml')]
        private string $path,
    ) {
    }

    #[Route('/api/docs.json', name: 'shared_docs', methods: [Request::METHOD_GET])]
    #[Cache(maxage: self::CACHE_SECONDS, public: true)]
    public function __invoke(): JsonResponse
    {
        return JsonResponse::fromJsonString(json_encode($this->document(), self::ENCODING));
    }

    private function document(): mixed
    {
        return Yaml::parseFile($this->path, self::PARSING);
    }
}
