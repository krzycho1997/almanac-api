<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;

final class Codebase
{
    public const string SHARED = 'Shared';

    /**
     * @return non-empty-list<string>
     */
    public static function modules(): array
    {
        $modules = array_values(array_diff(self::topLevelNamespaces(), [self::SHARED]));

        if ([] === $modules) {
            throw new \LogicException('No modules found under src/.');
        }

        return $modules;
    }

    public static function namespaceOf(string $module): SelectorInterface
    {
        return Selector::inNamespace("App\\{$module}");
    }

    public static function outsideApp(): SelectorInterface
    {
        return Selector::Not(Selector::inNamespace('App'));
    }

    public static function contracts(): SelectorInterface
    {
        return self::layer('Contract');
    }

    public static function layer(string ...$layers): SelectorInterface
    {
        return Selector::inNamespace(\sprintf('/^App\\\\%s\\\\%s(\\\\|$)/', self::alternation(self::topLevelNamespaces()), self::alternation($layers)), regex: true);
    }

    public static function moduleRoot(): SelectorInterface
    {
        return Selector::inNamespace(\sprintf('/^App\\\\%s$/', self::alternation(self::topLevelNamespaces())), regex: true);
    }

    /**
     * @return list<string>
     */
    private static function topLevelNamespaces(): array
    {
        $directories = glob(\dirname(__DIR__, 2).'/src/*', \GLOB_ONLYDIR);

        return array_map(basename(...), false === $directories ? [] : $directories);
    }

    /**
     * @param array<string> $names
     */
    private static function alternation(array $names): string
    {
        return '(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names)).')';
    }
}
