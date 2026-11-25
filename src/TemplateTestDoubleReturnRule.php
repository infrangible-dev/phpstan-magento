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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A function whose return tag is one of its own template types and that returns a test double of PHPUnit
 * (createStub(), createMock() and the like), directly or through a variable assigned from it. PhpStorm does not match
 * the test double with the template type and reports "Return type does not match the declared"; PHPStan accepts it.
 * Create the test doubles where they are used, or return a common parent class.
 * @implements Rule<FunctionLike>
 */
class TemplateTestDoubleReturnRule implements Rule
{
    /** The methods of PHPUnit's TestCase that create test doubles. */
    private const TEST_DOUBLE_METHODS = [
        'createstub',
        'createmock',
        'createconfiguredmock',
        'createconfiguredstub',
        'createpartialmock',
        'createtestproxy',
        'getmockforabstractclass'
    ];

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
        $docComment = $node->getDocComment();

        if ($stmts === null || $docComment === null) {
            return [];
        }

        $docText = $docComment->getText();

        if (preg_match('/@return\s+(\w+)\s*(?:\*\/|\n)/', $docText, $returnMatch) !== 1 ||
            preg_match('/@(?:phpstan-)?template\s+' . preg_quote($returnMatch[ 1 ], '/') . '\b/', $docText) !== 1) {

            return [];
        }

        $nodeFinder = new NodeFinder();
        $doubleVariables = [];

        foreach ($nodeFinder->find($stmts, fn(Node $found): bool => $found instanceof Expr\Assign) as $assign) {
            if (! $assign instanceof Expr\Assign) {
                continue;
            }

            $variable = $assign->var;

            if ($variable instanceof Expr\Variable && is_string($variable->name) &&
                $this->createsTestDouble($assign->expr)) {

                $doubleVariables[ $variable->name ] = true;
            }
        }

        $errors = [];

        foreach ($nodeFinder->find($stmts, fn(Node $found): bool => $found instanceof Stmt\Return_) as $return) {
            $expression = $return instanceof Stmt\Return_ ? $return->expr : null;

            if ($expression === null) {
                continue;
            }

            $variableName = $expression instanceof Expr\Variable ? $expression->name : null;

            if ($this->createsTestDouble($expression) ||
                (is_string($variableName) && isset($doubleVariables[ $variableName ]))) {

                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'A test double is returned as the template type %s, which PhpStorm reports as "Return type ' .
                        'does not match the declared". Create the test doubles where they are used.',
                        $returnMatch[ 1 ]
                    )
                )->line($return->getStartLine())->identifier('infrangible.templateTestDoubleReturn')->build();
            }
        }

        return $errors;
    }

    /**
     * Checks if the expression calls a method of PHPUnit that creates a test double.
     *
     * @param Expr $expression
     *
     * @return bool
     */
    private function createsTestDouble(Expr $expression): bool
    {
        if (! $expression instanceof Expr\MethodCall && ! $expression instanceof Expr\StaticCall) {
            return false;
        }

        $name = $expression->name;

        return $name instanceof Node\Identifier && in_array($name->toLowerString(), self::TEST_DOUBLE_METHODS, true);
    }
}
