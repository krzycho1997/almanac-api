<?php

declare(strict_types=1);

namespace App\PHPStan\Tests\Rule;

use App\PHPStan\Rule\DeclareStrictTypesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends RuleTestCase<DeclareStrictTypesRule>
 */
final class DeclareStrictTypesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DeclareStrictTypesRule();
    }

    #[DataProvider('filesWithoutStrictTypes')]
    public function testReportsFileWithoutStrictTypes(string $fixture): void
    {
        // Given
        $file = __DIR__.'/Fixtures/'.$fixture;

        // When
        $errors = $this->gatherAnalyserErrors([$file]);

        // Then
        self::assertCount(1, $errors);
        self::assertSame('File must start with declare(strict_types=1).', $errors[0]->getMessage());
        self::assertSame(1, $errors[0]->getLine());
        self::assertSame('almanac.declareStrictTypes', $errors[0]->getIdentifier());
        self::assertFalse($errors[0]->canBeIgnored());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesWithoutStrictTypes(): iterable
    {
        yield 'no declare' => ['no-declare.php'];
        yield 'strict_types=0' => ['strict-types-zero.php'];
        yield 'other declare' => ['other-declare.php'];
        yield 'empty file' => ['empty.php'];
        yield 'inline HTML only' => ['inline-html.php'];
        yield 'declare only in a comment' => ['declare-in-comment.php'];
    }

    #[DataProvider('filesWithStrictTypes')]
    public function testAcceptsFileWithStrictTypes(string $fixture): void
    {
        // Given
        $file = __DIR__.'/Fixtures/'.$fixture;

        // When
        $errors = $this->gatherAnalyserErrors([$file]);

        // Then
        self::assertSame([], $errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesWithStrictTypes(): iterable
    {
        yield 'strict_types=1' => ['strict-types.php'];
        yield 'after a shebang' => ['shebang.php'];
        yield 'after another directive' => ['strict-types-after-another-directive.php'];
        yield 'key in upper case' => ['strict-types-upper-case.php'];
    }
}
