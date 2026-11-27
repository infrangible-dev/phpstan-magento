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
use PhpParser\Node\FunctionLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method of the tested class called on a test double of PHPUnit whose class is given by a variable instead of a
 * literal like Template::class, for example $this->getMockBuilder($className)->getMock(). PHPStan takes the class from
 * the class-string type of the variable, PhpStorm only knows the test double interface and reports "Method 'x' not
 * found in MockObject". Create the test double with the literal class.
 * @implements Rule<FunctionLike>
 */
class VariableClassTestDoubleCallRule implements Rule
{
    /** The methods of PHPUnit's TestCase that create test doubles or their builders, the class as first argument. */
    private const TEST_DOUBLE_METHODS = [
        'createstub',
        'createmock',
        'createconfiguredmock',
        'createconfiguredstub',
        'createpartialmock',
        'createtestproxy',
        'getmockforabstractclass',
        'getmockbuilder'
    ];

    /** The methods of the test double interfaces of PHPUnit, which PhpStorm knows. */
    private const TEST_DOUBLE_INTERFACE_METHODS = ['method', 'expects'];

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @param FunctionLike $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $stmts = $node->getStmts();

        if ($stmts === null) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $doubleVariables = [];

        foreach ($nodeFinder->find($stmts, fn(Node $found): bool => $found instanceof Expr\Assign) as $assign) {
            $variableName = $assign instanceof Expr\Assign ? $this->variableName($assign->var) : null;

            if ($variableName !== null && $assign instanceof Expr\Assign &&
                $this->createsVariableClassTestDouble($assign->expr)) {

                $doubleVariables[ $variableName ] = true;
            }
        }

        if ($doubleVariables === []) {
            return [];
        }

        $errors = [];

        foreach ($nodeFinder->find($stmts, fn(Node $found): bool => $found instanceof Expr\MethodCall) as $call) {
            if (! $call instanceof Expr\MethodCall) {
                continue;
            }

            $variableName = $this->variableName($call->var);
            $methodName = $call->name;

            if ($variableName === null || ! isset($doubleVariables[ $variableName ]) ||
                ! $methodName instanceof Node\Identifier ||
                in_array($methodName->toLowerString(), self::TEST_DOUBLE_INTERFACE_METHODS, true)) {

                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Method %s() is called on a test double whose class is given by a variable, which PhpStorm ' .
                    'reports as "Method not found in MockObject". Create the test double with a literal class.',
                    $methodName->toString()
                )
            )->line($call->getStartLine())->identifier('infrangible.variableClassTestDoubleCall')->build();
        }

        return $errors;
    }

    /**
     * Returns the name of the variable, null for other expressions and variable variables.
     *
     * @param Expr $expression
     *
     * @return string|null
     */
    private function variableName(Expr $expression): ?string
    {
        $name = $expression instanceof Expr\Variable ? $expression->name : null;

        return is_string($name) ? $name : null;
    }

    /**
     * Checks if the expression creates a test double, directly or through a mock builder, from a class that is not given
     * as literal.
     *
     * @param Expr $expression
     *
     * @return bool
     */
    private function createsVariableClassTestDouble(Expr $expression): bool
    {
        while ($expression instanceof Expr\MethodCall) {
            $name = $expression->name;

            if ($name instanceof Node\Identifier &&
                in_array($name->toLowerString(), self::TEST_DOUBLE_METHODS, true)) {

                $argument = $expression->getArgs()[ 0 ] ?? null;

                if ($argument === null) {
                    return false;
                }

                $class = $argument->value;
                $constant = $class instanceof Expr\ClassConstFetch ? $class->name : null;

                return ! $constant instanceof Node\Identifier || $constant->toLowerString() !== 'class';
            }

            $expression = $expression->var;
        }

        return false;
    }
}
