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
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Collectors\Collector;

/**
 * Collects the suppressions of unused parameters, for RedundantUnusedParameterSuppressionRule.
 *
 * PhpStorm does not report unused parameters of methods that override or implement a method of a parent class or an
 * interface (the signature is given), and of course not if the method uses all its parameters. A
 * "@noinspection PhpUnusedParameterInspection" in the docblock of such a method is reported by PhpStorm as
 * "Redundant suppression".
 *
 * @implements Collector<InClassMethodNode, array{string, string, string|null, int}>
 */
class RedundantUnusedParameterSuppressionCollector implements Collector
{
    private const INSPECTION = 'PhpUnusedParameterInspection';

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @param InClassMethodNode $node
     * @param Scope $scope
     *
     * @return array{string, string, string|null, int}|null the key of the method (see OverriddenMethodCollector), its
     *     name, the reason why the suppression is redundant (null if the rule decides it) and its line
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $method = $node->getOriginalNode();
        $docComment = $method->getDocComment();

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null || $docComment === null ||
            preg_match('/@noinspection\s+[^\n]*\b' . self::INSPECTION . '\b/', $docComment->getText()) !== 1) {

            return null;
        }

        $methodName = $method->name->toString();

        $reason = match (true) {
            $this->overridesMethod($scope, $methodName) => 'overrides or implements a parent method',
            $this->usesAllParameters($method) => 'uses all its parameters',
            default => null
        };

        return [
            OverriddenMethodCollector::getKey($classReflection->getName(), $methodName),
            $methodName,
            $reason,
            $method->getStartLine()
        ];
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

        if ($classReflection === null || strtolower($methodName) === '__construct') {
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

        return false;
    }

    /**
     * Checks if the body of the method uses all parameters of the method.
     *
     * @param Node\Stmt\ClassMethod $method
     *
     * @return bool
     */
    private function usesAllParameters(Node\Stmt\ClassMethod $method): bool
    {
        if ($method->stmts === null || count($method->params) === 0) {
            return false;
        }

        $nodeFinder = new NodeFinder();

        $usedNames = [];

        $variables = $nodeFinder->find(
            $method->stmts,
            static fn(Node $node): bool => $node instanceof Expr\Variable
        );

        foreach ($variables as $variable) {
            if ($variable instanceof Expr\Variable) {
                $usedNames[] = $variable->name;
            }
        }

        foreach ($method->params as $param) {
            $variable = $param->var;

            if ($variable instanceof Expr\Variable && is_string($variable->name) &&
                ! in_array($variable->name, $usedNames, true)) {

                return false;
            }
        }

        return true;
    }
}
