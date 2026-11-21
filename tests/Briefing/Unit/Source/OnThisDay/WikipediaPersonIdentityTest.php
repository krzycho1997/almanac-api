<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\OnThisDay;

use App\Briefing\Discovery\PersonReference;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Source\OnThisDay\WikipediaPersonIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WikipediaPersonIdentityTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('people')]
    public function testNamesAPersonByItsWikidataItemThenItsPage(?string $url, ?string $wikidataId, array $expected): void
    {
        // Given
        $person = new NotablePerson(1900, 'Someone', null, $url, null, $wikidataId);

        // When
        $references = new WikipediaPersonIdentity()->referencesOf($person);

        // Then
        self::assertSame($expected, array_map(static fn (PersonReference $reference): string => $reference->key, $references));
    }

    /**
     * @return iterable<string, array{?string, ?string, list<string>}>
     */
    public static function people(): iterable
    {
        yield 'item and page' => ['https://en.wikipedia.org/wiki/Julius_Caesar', 'Q1048', ['person:Q1048', 'person:page:Julius Caesar']];
        yield 'item only' => [null, 'Q1048', ['person:Q1048']];
        yield 'page only' => ['https://en.wikipedia.org/wiki/John_Smith_(footballer)', null, ['person:page:John Smith (footballer)']];
        yield 'percent-encoded title' => ['https://en.wikipedia.org/wiki/Fr%C3%A9d%C3%A9ric_Chopin', null, ['person:page:Frédéric Chopin']];
        yield 'decomposed title normalised' => ["https://en.wikipedia.org/wiki/Fre\u{301}de\u{301}ric", null, ['person:page:Frédéric']];
        yield 'section of a list' => ['https://en.wikipedia.org/wiki/List_of_people#Doe', null, []];
        yield 'not an article' => ['https://example.org/people/doe', null, []];
        yield 'empty title' => ['https://en.wikipedia.org/wiki/_', null, []];
        yield 'control character' => ['https://en.wikipedia.org/wiki/Jane%09Doe', null, []];
        yield 'no address' => [null, null, []];
    }
}
