<?php

declare(strict_types=1);

namespace App\Shop\Dto\Request;

final readonly class OrderQuery
{
    public function __construct(
        public mixed $page = null,
    ) {
    }

    public function pageNumber(): int
    {
        return (int) $this->page;
    }
}

namespace App\Shop\Message;

final readonly class ShipOrderMessage
{
    public function __construct(
        public string $orderId,
    ) {
    }

    public static function of(string $orderId): self
    {
        return new self($orderId);
    }
}

namespace App\Shop\Dto\Input;

final class ImportInput
{
    public string $from = '';
}

namespace App\Shop\Dto\Response;

trait Formats
{
    public function formatted(): string
    {
        return '';
    }
}

final class PriceDto
{
    use Formats;

    public int $cents = 0 {
        get => $this->cents * 100;
    }

    public function __construct(
        public string $currency = 'EUR' {
            set => strtoupper($value);
        },
    ) {
    }
}

enum OrderState: string
{
    case Open = 'open';

    public function label(): string
    {
        return 'Open';
    }
}

namespace App\Shop\Dto\Request;

final class SearchQuery
{
    public string $term;

    public string $sort;

    public function __construct(
        string $term,
        public int $page = 1,
    ) {
        $this->term = trim($term);
        $this->sort = 'name';
        if ($page > 1) {
            $this->sort = 'rank';
        }
    }
}

namespace App\Shop\Message\Validator;

final readonly class RefundCheck
{
    public function allows(): bool
    {
        return true;
    }
}

namespace App\Shop\Dto\Response;

final class LabelDto
{
    public string $text;

    public string $origin;

    public string $kind;

    public function __construct(string $text, string $field)
    {
        $this->text = "{$text}!";
        $this->origin = $_SERVER['HOST'] ?? '';
        $this->{$field} = $text;
        $this->kind = $text::KIND;
    }
}

namespace App\Shop\Dto\Request\Validator;

final class ValidSearchValidator
{
    public function validate(): void
    {
    }
}
