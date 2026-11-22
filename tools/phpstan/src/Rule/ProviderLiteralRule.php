<?php

declare(strict_types=1);

namespace App\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<scalar>
 */
final readonly class ProviderLiteralRule implements Rule
{
    private const string MISSING_ROOT = 'The source root "%s" is not a directory.';
    private const string MESSAGE = 'The literal "%s" names a provider; it belongs in src/Briefing/Source/<Provider>/.';
    private const string IDENTIFIER = 'almanac.providerLiteral';
    private const string SOURCE_DEFINITIONS = 'Briefing/Source/*/*Source.php';
    private const array PROVIDER_PATTERNS = [
        '#/wiki/#',
        '#(?:^|[./])(?:wikipedia|wikimedia|wikidata)\.org#i',
        '#(?:^|[./])nasa\.gov#i',
        '#(?:^|[./])open-meteo\.com#i',
    ];
    private const string ADDRESS = '#https?://([^/:?\#\s]+)#i';

    private string $sourceRoot;

    /** @var list<string> */
    private array $providerDirectories;

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(string $sourceRoot, array $allowedHosts)
    {
        $this->allowedHosts = array_map(strtolower(...), $allowedHosts);
        $this->sourceRoot = self::directory($sourceRoot).'/';
        $this->providerDirectories = self::providerDirectoriesIn($this->sourceRoot);
    }

    public function getNodeType(): string
    {
        return Scalar::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isOutsideTheProviders($scope->getFile())) {
            return $this->errorsFor($node, self::literalOf($node));
        }

        return [];
    }

    private static function directory(string $path): string
    {
        $directory = realpath($path);

        if (\is_string($directory) && is_dir($directory)) {
            return $directory;
        }

        throw new \InvalidArgumentException(\sprintf(self::MISSING_ROOT, $path));
    }

    /**
     * @return list<string>
     */
    private static function providerDirectoriesIn(string $sourceRoot): array
    {
        $definitions = glob($sourceRoot.self::SOURCE_DEFINITIONS);

        if (\is_array($definitions)) {
            return array_values(array_unique(array_map(static fn (string $definition): string => \dirname($definition).'/', $definitions)));
        }

        return [];
    }

    private function isOutsideTheProviders(string $file): bool
    {
        if (str_starts_with($file, $this->sourceRoot)) {
            return $this->isInNoProviderDirectory($file);
        }

        return false;
    }

    private function isInNoProviderDirectory(string $file): bool
    {
        foreach ($this->providerDirectories as $directory) {
            if (str_starts_with($file, $directory)) {
                return false;
            }
        }

        return true;
    }

    private static function literalOf(Scalar $node): string
    {
        return match (true) {
            $node instanceof String_ => $node->value,
            $node instanceof InterpolatedString => self::literalPartsOf($node),
            default => '',
        };
    }

    private static function literalPartsOf(InterpolatedString $node): string
    {
        $parts = array_filter($node->parts, static fn (Node $part): bool => $part instanceof InterpolatedStringPart);

        return implode('', array_map(static fn (InterpolatedStringPart $part): string => $part->value, $parts));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function errorsFor(Scalar $node, string $literal): array
    {
        if ($this->namesAProvider($literal)) {
            return [self::error($node, $literal)];
        }

        return [];
    }

    private function namesAProvider(string $value): bool
    {
        foreach (self::PROVIDER_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $value)) {
                return true;
            }
        }

        return $this->hasAnAddressOutsideTheAllowedHosts($value);
    }

    private function hasAnAddressOutsideTheAllowedHosts(string $value): bool
    {
        preg_match_all(self::ADDRESS, $value, $matches);

        foreach ($matches[1] as $host) {
            if (\in_array(strtolower($host), $this->allowedHosts, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function error(Scalar $node, string $literal): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(self::MESSAGE, $literal))
            ->identifier(self::IDENTIFIER)
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
