<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A parameter of a private method that every call of the method passes the same constant value, like
 * `createResource([7])` in all calls.
 *
 * PhpStorm reports it as "Parameter's value is always '[7]'": the value belongs into the method. Private methods are
 * only called by their class, so all calls are known; a method that is also referenced in another way than by a
 * direct call, like a callable or its name as a string, is skipped.
 *
 * @implements Rule<InClassNode>
 */
class SameParameterValueRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();
        $finder = new NodeFinder();

        /** @var array<string, Stmt\ClassMethod> $methods private methods by lower-case name */
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if ($method->isPrivate() && ! $method->isAbstract() && count($method->getParams()) > 0) {
                $methods[ $method->name->toLowerString() ] = $method;
            }
        }

        if (count($methods) === 0) {
            return [];
        }

        /** @var array<string, list<Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall>> $calls */
        $calls = [];
        /** @var array<string, true> $otherReferences methods referenced in another way than by a direct call */
        $otherReferences = [];

        foreach ($finder->find($class->stmts, fn(Node $found): bool => true) as $found) {
            if ($found instanceof Scalar\String_) {
                $otherReferences[ strtolower($found->value) ] = true;

                continue;
            }

            if (! ($found instanceof Expr\MethodCall || $found instanceof Expr\NullsafeMethodCall ||
                $found instanceof Expr\StaticCall)) {

                continue;
            }

            $identifier = $found->name;

            if (! $identifier instanceof Identifier) {
                continue;
            }

            $name = $identifier->toLowerString();

            if (! isset($methods[ $name ])) {
                continue;
            }

            if ($found->isFirstClassCallable() || ! $this->isOwnCall($found)) {
                $otherReferences[ $name ] = true;

                continue;
            }

            $calls[ $name ][] = $found;
        }

        $printer = new Standard();
        $errors = [];

        foreach ($methods as $name => $method) {
            if (isset($otherReferences[ $name ]) || ! isset($calls[ $name ])) {
                continue;
            }

            foreach ($method->getParams() as $position => $param) {
                $variable = $param->var;
                $parameterName = $variable instanceof Expr\Variable ? $variable->name : null;

                if ($param->variadic || $param->byRef || ! is_string($parameterName)) {
                    continue;
                }

                $value = $this->sameValue($calls[ $name ], $position, $parameterName, $param->default, $printer);

                if ($value === null) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'Parameter $%s of %s() is always %s in all calls, use the value in the method.',
                        $parameterName,
                        $method->name->toString(),
                        $value
                    )
                )->identifier('infrangible.sameParameterValue')->line($param->getStartLine())->build();
            }
        }

        return $errors;
    }

    /**
     * Whether the call is one of the class itself: on $this, self or static.
     *
     * @param Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call
     *
     * @return bool
     */
    private function isOwnCall(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): bool
    {
        if ($call instanceof Expr\StaticCall) {
            $class = $call->class;

            return $class instanceof Name && in_array($class->toLowerString(), ['self', 'static'], true);
        }

        $variable = $call->var;

        return $variable instanceof Expr\Variable && $variable->name === 'this';
    }

    /**
     * The printed constant value that all calls pass for the parameter, null if they differ or a value is not constant.
     *
     * @param list<Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall> $calls
     * @param int $position
     * @param string $name
     * @param Expr|null $default
     * @param Standard $printer
     *
     * @return string|null
     */
    private function sameValue(array $calls, int $position, string $name, ?Expr $default, Standard $printer): ?string
    {
        $value = null;

        foreach ($calls as $call) {
            $argument = $this->argument($call, $position, $name);

            if ($argument === false) {
                return null;
            }

            $argument ??= $default;

            if ($argument === null || ! $this->isConstant($argument)) {
                return null;
            }

            $printed = $printer->prettyPrintExpr($argument);

            if ($value !== null && $printed !== $value) {
                return null;
            }

            $value = $printed;
        }

        return $value;
    }

    /**
     * The argument of the call for the parameter, null if the call leaves it out, false if it is unknown because of an
     * unpacked argument.
     *
     * @param Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call
     * @param int $position
     * @param string $name
     *
     * @return Expr|false|null
     */
    private function argument(
        Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call,
        int $position,
        string $name
    ): Expr|false|null {
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return false;
            }

            if ($arg->name !== null) {
                if ($arg->name->toString() === $name) {
                    return $arg->value;
                }

                continue;
            }

            if ($index === $position) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * Whether the expression is a constant value: a literal, a constant or an array of them.
     *
     * The node types of arrays and their items differ between the versions of PHP-Parser that PHPStan 1 and 2 use, so
     * they are compared by their type names.
     *
     * @param Expr $expr
     *
     * @return bool
     */
    private function isConstant(Expr $expr): bool
    {
        $constantTypes = ['Expr_ConstFetch', 'Expr_ClassConstFetch', 'Expr_UnaryMinus', 'Expr_Array', 'Expr_ArrayItem'];

        // a string with variables has them as parts; nodes that are no expressions are names and items of arrays
        $nonConstant = (new NodeFinder())->findFirst(
            $expr,
            fn(Node $found): bool => $found instanceof Expr && ! $found instanceof Scalar &&
                ! in_array($found->getType(), $constantTypes, true)
        );

        return $nonConstant === null;
    }
}
