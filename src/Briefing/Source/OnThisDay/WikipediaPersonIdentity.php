<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use App\Briefing\Discovery\PersonIdentity;
use App\Briefing\Discovery\PersonReference;
use App\Briefing\Model\NotablePerson;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PersonIdentity::class)]
final readonly class WikipediaPersonIdentity implements PersonIdentity
{
    private const string CONTROL_CHARACTER = '/\p{Cc}/u';
    private const string WIKI_PATH = '/wiki/';
    private const string UNDERSCORE = '_';
    private const string SPACE = ' ';
    private const string FRAGMENT = '#';

    public function referencesOf(NotablePerson $person): array
    {
        return [...self::wikidataItemOf($person), ...self::pageOf($person->url)];
    }

    /**
     * @return list<PersonReference>
     */
    private static function wikidataItemOf(NotablePerson $person): array
    {
        if (\is_string($person->catalogueId)) {
            return [PersonReference::catalogued($person->catalogueId)];
        }

        return [];
    }

    /**
     * @return list<PersonReference>
     */
    private static function pageOf(?string $url): array
    {
        $title = self::pageTitleOf($url);

        if (\is_string($title)) {
            return [PersonReference::page($title)];
        }

        return [];
    }

    private static function pageTitleOf(?string $url): ?string
    {
        if (str_contains((string) $url, self::FRAGMENT)) {
            return null;
        }

        $path = (string) parse_url((string) $url, \PHP_URL_PATH);

        if (str_starts_with($path, self::WIKI_PATH)) {
            return self::titleIn(rawurldecode(substr($path, \strlen(self::WIKI_PATH))));
        }

        return null;
    }

    private static function titleIn(string $decoded): ?string
    {
        $title = \Normalizer::normalize(trim(str_replace(self::UNDERSCORE, self::SPACE, $decoded)), \Normalizer::FORM_C);

        if (\is_string($title)) {
            return self::usable($title);
        }

        return null;
    }

    private static function usable(string $title): ?string
    {
        if ('' === $title) {
            return null;
        }

        if (1 === preg_match(self::CONTROL_CHARACTER, $title)) {
            return null;
        }

        return $title;
    }
}
