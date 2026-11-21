<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Request;

use App\Bookmark\PageRequest;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BookmarkListQuery
{
    private const string WHOLE_NUMBER = '/^\d+$/D';

    public function __construct(
        #[Assert\Sequentially([
            new Assert\Type('string'),
            new Assert\Regex(self::WHOLE_NUMBER),
            new Assert\Range(min: PageRequest::FIRST_PAGE, max: PageRequest::LAST_PAGE),
        ])]
        public mixed $page = null,
        #[Assert\Sequentially([
            new Assert\Type('string'),
            new Assert\Regex(self::WHOLE_NUMBER),
            new Assert\Range(min: PageRequest::SMALLEST_LIMIT, max: PageRequest::LARGEST_LIMIT),
        ])]
        public mixed $limit = null,
    ) {
    }

    public function pageRequest(): PageRequest
    {
        return new PageRequest(self::number($this->page, PageRequest::FIRST_PAGE), self::number($this->limit, PageRequest::DEFAULT_LIMIT));
    }

    private static function number(mixed $value, int $default): int
    {
        if (\is_string($value)) {
            return (int) $value;
        }

        return $default;
    }
}
