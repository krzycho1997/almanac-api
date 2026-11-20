<?php

declare(strict_types=1);

namespace App\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final class DeclareStrictTypesRule implements Rule
{
    private const string MESSAGE = 'File must start with declare(strict_types=1).';
    private const string IDENTIFIER = 'almanac.declareStrictTypes';
    private const string DIRECTIVE = 'strict_types';
    private const int ENABLED = 1;

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (self::startsWithStrictTypes($node->getNodes())) {
            return [];
        }

        return [self::error()];
    }

    /**
     * @param array<Node> $statements
     */
    private static function startsWithStrictTypes(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof InlineHTML) {
                continue;
            }

            return $statement instanceof Declare_ && self::declaresStrictTypes($statement);
        }

        return false;
    }

    private static function declaresStrictTypes(Declare_ $declare): bool
    {
        foreach ($declare->declares as $item) {
            if (self::DIRECTIVE === $item->key->toLowerString()) {
                return self::isEnabled($item->value);
            }
        }

        return false;
    }

    private static function isEnabled(Node\Expr $value): bool
    {
        if ($value instanceof Int_) {
            return self::ENABLED === $value->value;
        }

        return false;
    }

    private static function error(): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::MESSAGE)
            ->identifier(self::IDENTIFIER)
            ->line(1)
            ->nonIgnorable()
            ->build();
    }
}
