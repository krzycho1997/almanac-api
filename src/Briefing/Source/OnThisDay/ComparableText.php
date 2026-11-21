<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final class ComparableText
{
    private const string RULES = 'NFD; [:Nonspacing Mark:] Remove; Lower(); NFC';

    private static ?\Transliterator $transliterator = null;

    public static function of(string $text): string
    {
        return (string) self::transliterator()->transliterate($text);
    }

    private static function transliterator(): \Transliterator
    {
        return self::$transliterator ??= \Transliterator::create(self::RULES) ?? throw new \LogicException('The transliterator rules are invalid.');
    }
}
