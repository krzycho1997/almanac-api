<?php

declare(strict_types=1);

namespace App\Briefing\Source;

final class PlainText
{
    private const string WHITESPACE_RUN = '/[\s\p{Cc}]+/u';
    private const string SPACE_BEFORE_PUNCTUATION = '/\s+([.,;:!?])(?=\s|$)/u';
    private const string SPACE = ' ';
    private const string PUNCTUATION = '$1';
    private const string COMMENT = '/<!--.*?(?:-->|$)/su';
    private const string TAG = '/<\/?[a-z][^<>]*(?:>|$)/iu';
    private const string ENCODING = 'UTF-8';

    public static function tidy(string $text): string
    {
        return trim((string) preg_replace([self::WHITESPACE_RUN, self::SPACE_BEFORE_PUNCTUATION], [self::SPACE, self::PUNCTUATION], $text));
    }

    public static function fromMarkup(string $text): string
    {
        $decoded = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, self::ENCODING);

        return self::tidy((string) preg_replace([self::COMMENT, self::TAG], self::SPACE, $decoded));
    }
}
