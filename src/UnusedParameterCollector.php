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
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Collectors\Collector;
use PHPStan\TrinaryLogic;

/**
 * Collects the parameters that their method does not use, for UnusedParameterRule.
 *
 * PhpStorm reports it as "Unused parameter '$x'. The parameter's value is not used anywhere.", except for methods that
 * override or implement a method of a parent class or an interface, whose signature is given. Methods with a given
 * signature that PhpStorm does not know, like the before, after and around methods of Magento plugins, need
 * "@noinspection PhpUnusedParameterInspection" in their docblock. Promoted constructor parameters are properties and
 * count as used, methods without statements are hooks for subclasses and are not checked. Methods that a subclass
 * overrides are left out by the rule.
 *
 * @implements Collector<InClassMethodNode, list<array{string, string, string, int}>>
 */
class UnusedParameterCollector implements Collector
{
    public const INSPECTION = 'PhpUnusedParameterInspection';

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @param InClassMethodNode $node
     * @param Scope $scope
     *
     * @return list<array{string, string, string, int}>|null the key of the method (see OverriddenMethodCollector),
     *     its name, the name and the line of every unused parameter
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $method = $node->getOriginalNode();

        // methods without statements are hooks for subclasses, which use the parameters
        $statements = array_filter(
            $method->stmts ?? [],
            static fn(Node\Stmt $statement): bool => ! $statement instanceof Node\Stmt\Nop
        );

        if (count($statements) === 0 || count($method->params) === 0 || $this->isSuppressed($method) ||
            $this->overridesMethod($scope, $method->name->toString())) {

            return null;
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return null;
        }

        $methodName = $method->name->toString();
        $methodKey = OverriddenMethodCollector::getKey($classReflection->getName(), $methodName);

        $usedNames = $this->getUsedNames($statements);
        $parameters = [];

        foreach ($method->params as $param) {
            $variable = $param->var;

            if ($param->flags !== 0 || ! $variable instanceof Expr\Variable || ! is_string($variable->name) ||
                in_array($variable->name, $usedNames, true)) {

                continue;
            }

            $parameters[] = [$methodKey, $methodName, $variable->name, $param->getStartLine()];
        }

        return count($parameters) > 0 ? $parameters : null;
    }

    /**
     * Returns the names of the variables used in the statements, also in compact() and with func_get_args() all.
     *
     * @param Node\Stmt[] $statements
     *
     * @return list<string|null> null if all parameters are used (func_get_args() or variable variables)
     */
    private function getUsedNames(array $statements): array
    {
        $nodeFinder = new NodeFinder();
        $names = [];

        $variables = $nodeFinder->find(
            $statements,
            static fn(Node $node): bool => $node instanceof Expr\Variable
        );

        foreach ($variables as $variable) {
            if ($variable instanceof Expr\Variable) {
                $names[] = is_string($variable->name) ? $variable->name : null;
            }
        }

        $calls = $nodeFinder->find(
            $statements,
            static fn(Node $node): bool => $node instanceof Expr\FuncCall
        );

        foreach ($calls as $call) {
            if (! $call instanceof Expr\FuncCall) {
                continue;
            }

            $callName = $call->name;

            if (! $callName instanceof Node\Name) {
                continue;
            }

            $functionName = $callName->toLowerString();

            if (in_array($functionName, ['func_get_args', 'func_get_arg', 'get_defined_vars'], true)) {
                $names[] = null;
            }

            if ($functionName === 'compact') {
                $strings = $nodeFinder->find(
                    $call->getArgs(),
                    static fn(Node $node): bool => $node instanceof String_
                );

                foreach ($strings as $string) {
                    if ($string instanceof String_) {
                        $names[] = $string->value;
                    }
                }
            }
        }

        // variable variables can use every parameter
        return in_array(null, $names, true) ? [null] : $names;
    }

    /**
     * Checks if the method overrides or implements a method of a parent class or an interface.
     *
     * @param Scope $scope
     * @param string $methodName
     *
     * @return bool
     */
    private function overridesMethod(Scope $scope, string $methodName): bool
    {
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return false;
        }

        $parentClass = $classReflection->getParentClass();

        if ($parentClass !== null && $parentClass->hasMethod($methodName)) {
            return true;
        }

        foreach ($classReflection->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                return true;
            }
        }

        foreach ($classReflection->getTraits(true) as $trait) {
            if (! $trait->hasNativeMethod($methodName)) {
                continue;
            }

            // PHPStan 1.x returns a bool, 2.x a TrinaryLogic
            $abstract = $trait->getNativeMethod($methodName)->isAbstract();

            if ($abstract instanceof TrinaryLogic ? $abstract->yes() : $abstract) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the docblock of the method suppresses the inspection.
     *
     * @param Node\Stmt\ClassMethod $method
     *
     * @return bool
     */
    private function isSuppressed(Node\Stmt\ClassMethod $method): bool
    {
        $docComment = $method->getDocComment();

        return $docComment !== null &&
            preg_match('/@noinspection\s+[^\n]*\b(' . self::INSPECTION . '|ALL)\b/', $docComment->getText()) === 1;
    }
}
