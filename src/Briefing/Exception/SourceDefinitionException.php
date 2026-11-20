<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

use App\Briefing\Source\Source;
use App\Briefing\SourceName;

final class SourceDefinitionException extends \LogicException
{
    /**
     * @param list<string> $services
     */
    public static function notExactlyOne(SourceName $name, array $services): self
    {
        return new self(\sprintf('Source name "%s" needs exactly one %s service, found %d: [%s].', $name->value, Source::class, \count($services), implode(', ', $services)));
    }

    public static function classNotFound(string $serviceId): self
    {
        return new self(\sprintf('The service "%s" is tagged as %s but its class cannot be found.', $serviceId, Source::class));
    }

    public static function notASource(string $serviceId, string $class): self
    {
        return new self(\sprintf('The service "%s" is tagged as %s but its class %s does not implement it.', $serviceId, Source::class, $class));
    }

    public static function nameNotDeclared(string $serviceId, string $class): self
    {
        return new self(\sprintf('The source class %s of the service "%s" must declare its name as the constant NAME of type %s.', $class, $serviceId, SourceName::class));
    }
}
