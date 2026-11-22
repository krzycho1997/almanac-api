<?php

declare(strict_types=1);

namespace App\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Console\Attribute\Interact;

/**
 * @implements Rule<InClassNode>
 */
final class DataCarrierRule implements Rule
{
    private const string MESSAGE = '%s carries data only; move %s() to a mapper or a validator.';
    private const string HOOK_MESSAGE = '%s carries data only; move the hooks of $%s to a mapper or a validator.';
    private const string TRAIT_MESSAGE = '%s carries data only; it may not use a trait.';
    private const string CONSTRUCTOR_BODY_MESSAGE = '%s carries data only; its constructor may only assign a parameter, a constant or a literal to a property.';
    private const string IDENTIFIER = 'almanac.dataCarrier';
    private const string CARRIER = '/^App\\\\[^\\\\]+\\\\(?:Dto|Message)\\\\/';
    private const string INTERACT = Interact::class;
    private const string CONSTRUCTOR = '__construct';
    private const string THIS = 'this';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();

        if (1 === preg_match(self::CARRIER, $class)) {
            return self::errorsFor($class, $node->getOriginalNode());
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function errorsFor(string $class, ClassLike $declaration): array
    {
        return array_merge(...array_map(static fn (Node $statement): array => self::errorsOf($class, $statement), $declaration->stmts));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function errorsOf(string $class, Node $statement): array
    {
        return match (true) {
            $statement instanceof ClassMethod => self::methodErrors($class, $statement),
            $statement instanceof Property => self::hookErrors($class, $statement),
            $statement instanceof TraitUse => [self::error(\sprintf(self::TRAIT_MESSAGE, $class), $statement)],
            default => [],
        };
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function methodErrors(string $class, ClassMethod $method): array
    {
        if (self::CONSTRUCTOR === $method->name->toLowerString()) {
            return self::constructorErrors($class, $method);
        }

        if (self::asksForInput($method)) {
            return [];
        }

        return [self::error(\sprintf(self::MESSAGE, $class, $method->name->toString()), $method)];
    }

    private static function asksForInput(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::INTERACT === $attribute->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function constructorErrors(string $class, ClassMethod $constructor): array
    {
        $parameters = array_map(static fn (Param $parameter): string => self::nameOf($parameter->var), $constructor->params);

        return array_merge(
            ...array_map(static fn (Param $parameter): array => self::parameterErrors($class, $parameter), $constructor->params),
            ...array_map(static fn (Node\Stmt $statement): array => self::statementErrors($class, $statement, $parameters), $constructor->stmts ?? []),
        );
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function parameterErrors(string $class, Param $parameter): array
    {
        if ([] === $parameter->hooks) {
            return [];
        }

        return [self::error(\sprintf(self::HOOK_MESSAGE, $class, self::nameOf($parameter->var)), $parameter)];
    }

    /**
     * @param array<string> $parameters
     *
     * @return list<IdentifierRuleError>
     */
    private static function statementErrors(string $class, Node\Stmt $statement, array $parameters): array
    {
        if (self::isAPlainAssignment($statement, $parameters)) {
            return [];
        }

        return [self::error(\sprintf(self::CONSTRUCTOR_BODY_MESSAGE, $class), $statement)];
    }

    /**
     * @param array<string> $parameters
     */
    private static function isAPlainAssignment(Node\Stmt $statement, array $parameters): bool
    {
        if ($statement instanceof Expression && $statement->expr instanceof Assign) {
            return self::isOwnProperty($statement->expr->var) && self::isPlainValue($statement->expr->expr, $parameters);
        }

        return false;
    }

    private static function isOwnProperty(Node\Expr $target): bool
    {
        if ($target instanceof PropertyFetch && $target->name instanceof Identifier) {
            return self::isThis($target->var);
        }

        return false;
    }

    private static function isThis(Node\Expr $variable): bool
    {
        return $variable instanceof Variable && self::THIS === $variable->name;
    }

    /**
     * @param array<string> $parameters
     */
    private static function isPlainValue(Node\Expr $value, array $parameters): bool
    {
        return match (true) {
            $value instanceof Variable => \in_array($value->name, $parameters, true),
            $value instanceof ClassConstFetch => $value->class instanceof Name && $value->name instanceof Identifier,
            default => $value instanceof ConstFetch || $value instanceof String_ || $value instanceof Int_ || $value instanceof Float_,
        };
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function hookErrors(string $class, Property $property): array
    {
        if ([] === $property->hooks) {
            return [];
        }

        return [self::error(\sprintf(self::HOOK_MESSAGE, $class, $property->props[0]->name->toString()), $property)];
    }

    private static function nameOf(Node\Expr $variable): string
    {
        if ($variable instanceof Variable && \is_string($variable->name)) {
            return $variable->name;
        }

        return '';
    }

    private static function error(string $message, Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier(self::IDENTIFIER)
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
