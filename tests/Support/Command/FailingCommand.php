<?php

declare(strict_types=1);

namespace App\Tests\Support\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: self::NAME)]
final readonly class FailingCommand
{
    public const string NAME = 'test:fail';

    public function __invoke(#[Argument] string $value): int
    {
        throw new \RuntimeException('The command failed.');
    }
}
