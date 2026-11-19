<?php

declare(strict_types=1);

namespace App\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final class DeclareStrictTypesRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->getNodes() as $statement) {
            if ($statement instanceof InlineHTML) {
                continue;
            }

            if ($statement instanceof Declare_ && $this->declaresStrictTypes($statement)) {
                return [];
            }

            break;
        }

        return [
            RuleErrorBuilder::message('File must start with declare(strict_types=1).')
                ->identifier('almanac.declareStrictTypes')
                ->line(1)
                ->nonIgnorable()
                ->build(),
        ];
    }

    private function declaresStrictTypes(Declare_ $declare): bool
    {
        foreach ($declare->declares as $item) {
            if ('strict_types' === $item->key->toLowerString()) {
                return $item->value instanceof Int_ && 1 === $item->value->value;
            }
        }

        return false;
    }
}
