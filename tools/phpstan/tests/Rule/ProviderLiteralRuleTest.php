<?php

declare(strict_types=1);

namespace App\PHPStan\Tests\Rule;

use App\PHPStan\Rule\ProviderLiteralRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ProviderLiteralRule>
 */
final class ProviderLiteralRuleTest extends RuleTestCase
{
    private const string SOURCE_ROOT = __DIR__.'/Fixtures/ProviderLiteral/src';
    private const array ALLOWED_HOSTS = ['Tools.IETF.org', 'www.rfc-editor.org', 'symfony.com', 'creativecommons.org'];

    protected function getRule(): Rule
    {
        return new ProviderLiteralRule(self::SOURCE_ROOT, self::ALLOWED_HOSTS);
    }

    public function testReportsProviderLiteralsOutsideTheProviderDirectories(): void
    {
        // When
        $errors = $this->gatherAnalyserErrors([
            self::SOURCE_ROOT.'/Briefing/Discovery/ProbeKnowsWikipedia.php',
            self::SOURCE_ROOT.'/Briefing/Source/ProbeSharedSourceCode.php',
            self::SOURCE_ROOT.'/Briefing/Discovery/ProbeBuildsAddresses.php',
            self::SOURCE_ROOT.'/Briefing/Source/Shared/ProbeSharedHelper.php',
        ]);

        // Then
        self::assertSame([
            [9, '/wiki/'],
            [10, 'https://en.wikipedia.org/wiki/Bohemia'],
            [11, 'en.wikipedia.org'],
            [12, 'https://api.nasa.gov/planetary/apod'],
            [13, 'https://archive-api.open-meteo.com/v1/archive'],
            [14, 'https://example.com/feed'],
            [9, 'api.open-meteo.com'],
            [11, 'https:///wiki/'],
            [16, 'https://www.wikidata.org/wiki/'],
            [23, 'https://API.OPEN-METEO.COM/v1/forecast'],
            [9, 'en.wikipedia.org'],
        ], array_map(static fn ($error): array => [$error->getLine(), self::literalIn($error->getMessage())], $errors));
        self::assertSame(['almanac.providerLiteral'], array_values(array_unique(array_map(static fn ($error): ?string => $error->getIdentifier(), $errors))));
        self::assertFalse($errors[0]->canBeIgnored());
    }

    public function testAcceptsProviderLiteralsInsideTheirProviderDirectory(): void
    {
        // When
        $errors = $this->gatherAnalyserErrors([
            self::SOURCE_ROOT.'/Briefing/Source/OnThisDay/ProbeProviderCode.php',
            self::SOURCE_ROOT.'/Briefing/Source/OnThisDay/ProbeSource.php',
        ]);

        // Then
        self::assertSame([], $errors);
    }

    public function testAcceptsStandardAddressesAndOrdinaryWords(): void
    {
        // When
        $errors = $this->gatherAnalyserErrors([self::SOURCE_ROOT.'/Shared/ProbeAllowedLiterals.php']);

        // Then
        self::assertSame([], $errors);
    }

    public function testRefusesASourceRootThatIsNotADirectory(): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);

        new ProviderLiteralRule(self::SOURCE_ROOT.'/Missing', self::ALLOWED_HOSTS);
    }

    private static function literalIn(string $message): string
    {
        self::assertSame(1, preg_match('/^The literal "(.*)" names a provider/', $message, $matches));

        return $matches[1];
    }
}
