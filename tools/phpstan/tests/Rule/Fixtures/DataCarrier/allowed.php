<?php

declare(strict_types=1);

namespace App\Shop\Dto\Input\Validator;

final class ValidImport
{
    public function getTargets(): string
    {
        return 'class';
    }
}

namespace App\Shop\Mapper;

final readonly class OrderQueryMapper
{
    public function convertToPage(): int
    {
        return 1;
    }
}

namespace App\Shop;

final readonly class Order
{
    public function total(): int
    {
        return 0;
    }
}

namespace App\Shop\MessageHandler;

final readonly class ShipOrderHandler
{
    public function __invoke(): void
    {
    }
}

namespace App\Shop\Legacy\Dto;

final readonly class ImportRow
{
    public function cells(): array
    {
        return [];
    }
}

namespace App\Shop\Dto\Input;

use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\Interact as AskMore;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestockInput
{
    #[Ask('Which product?')]
    public string $product = '';

    #[AskMore]
    public function askForTheAmount(SymfonyStyle $io): void
    {
    }
}

namespace App\Shop\Dto\Request\Validator;

final class ValidSearchValidator
{
    public function validate(): void
    {
    }
}

namespace App\Shop\Dto\Response;

final readonly class StockDto
{
    private const bool KNOWN = true;

    public string $state;

    public int $count;

    public bool $known;

    public float $ratio;

    public int $shelf;

    public string $unit;

    public function __construct(int $count)
    {
        $this->state = 'in_stock';
        $this->count = $count;
        $this->known = self::KNOWN;
        $this->ratio = 0.5;
        $this->shelf = 3;
        $this->unit = \PHP_EOL;
    }
}
