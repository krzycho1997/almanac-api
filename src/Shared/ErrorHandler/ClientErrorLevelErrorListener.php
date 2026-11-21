<?php

declare(strict_types=1);

namespace App\Shared\ErrorHandler;

use App\Shared\Logging\ClientError;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;

#[Exclude]
final class ClientErrorLevelErrorListener extends ErrorListener
{
    private const string FRAMEWORK_CLIENT_ERROR_LEVEL = LogLevel::ERROR;
    private const string CLIENT_ERROR_LEVEL = LogLevel::NOTICE;
    private const string LOG_LEVEL = 'log_level';

    protected function logException(\Throwable $exception, string $message, ?string $logLevel = null, ?string $logChannel = null): void
    {
        parent::logException($exception, $message, $this->levelFor($exception, $logLevel), $logChannel);
    }

    private function levelFor(\Throwable $exception, ?string $logLevel): ?string
    {
        if ($this->isFrameworkDefaultForClientError($exception, $logLevel)) {
            return self::CLIENT_ERROR_LEVEL;
        }

        return $logLevel;
    }

    private function isFrameworkDefaultForClientError(\Throwable $exception, ?string $logLevel): bool
    {
        if (self::FRAMEWORK_CLIENT_ERROR_LEVEL === $logLevel) {
            return ClientError::matches($exception) && $this->isLevelLeftToTheFramework($exception);
        }

        return false;
    }

    private function isLevelLeftToTheFramework(\Throwable $exception): bool
    {
        return array_all(self::thrownAndOriginal($exception), fn (\Throwable $throwable): bool => $this->hasNoConfiguredLevel($throwable));
    }

    /**
     * @return list<\Throwable>
     */
    private static function thrownAndOriginal(\Throwable $exception): array
    {
        $original = $exception->getPrevious();

        if ($original instanceof \Throwable) {
            return [$exception, $original];
        }

        return [$exception];
    }

    private function hasNoConfiguredLevel(\Throwable $throwable): bool
    {
        if ($this->isMappedWithALevel($throwable)) {
            return false;
        }

        return self::hasNoLevelAttribute($throwable);
    }

    private function isMappedWithALevel(\Throwable $throwable): bool
    {
        return array_any(
            $this->exceptionsMapping,
            static fn (array $config, string $class): bool => $throwable instanceof $class && isset($config[self::LOG_LEVEL]),
        );
    }

    private static function hasNoLevelAttribute(\Throwable $throwable): bool
    {
        return array_all(
            [$throwable::class, ...class_parents($throwable), ...class_implements($throwable)],
            static fn (string $type): bool => [] === new \ReflectionClass($type)->getAttributes(WithLogLevel::class),
        );
    }
}
