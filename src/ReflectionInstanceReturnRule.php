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
 * A function with a class as native return type that returns an instance created by reflection (newInstance(),
 * newInstanceArgs(), newInstanceWithoutConstructor()), directly or through a variable assigned from it.
 * PhpStorm types such instances as "object|string" and reports "Return value is expected to be 'X', 'object|string'
 * returned"; PHPStan knows the class from the template type. Declare "object" as native return type and the class
 * (or template type) in the return tag.
 * @implements Rule<FunctionLike>
 */
class ReflectionInstanceReturnRule implements Rule
{
    /** The methods of ReflectionClass that create instances. */
    private const INSTANCE_METHODS = ['newinstance', 'newinstanceargs', 'newinstancewithoutconstructor'];

    /** Native return types that accept the instances. */
    private const ACCEPTING_TYPES = ['object', 'mixed', 'static', 'self'];

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
        $returnType = $node->getReturnType();
        $stmts = $node->getStmts();

        if (! $returnType instanceof Node\Name || $stmts === null ||
            in_array($returnType->toLowerString(), self::ACCEPTING_TYPES, true)) {

            return [];
        }

        $nodeFinder = new NodeFinder();
        $instanceVariables = [];

        foreach ($nodeFinder->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            $variable = $assign->var;

            if ($variable instanceof Expr\Variable && is_string($variable->name) &&
                $this->createsInstance($assign->expr)) {

                $instanceVariables[ $variable->name ] = true;
            }
        }

        $errors = [];

        foreach ($nodeFinder->findInstanceOf($stmts, Stmt\Return_::class) as $return) {
            $expression = $return->expr;

            if ($expression === null) {
                continue;
            }

            $variableName = $expression instanceof Expr\Variable ? $expression->name : null;

            if ($this->createsInstance($expression) ||
                (is_string($variableName) && isset($instanceVariables[ $variableName ]))) {

                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'The instance created by reflection is returned with the native return type %s, PhpStorm ' .
                        'types it as object|string. Declare "object" as native return type and the class in the ' .
                        'return tag.',
                        $returnType->toString()
                    )
                )->line($return->getStartLine())->identifier('infrangible.reflectionInstanceReturn')->build();
            }
        }

        return $errors;
    }

    /**
     * Checks if the expression calls a method of ReflectionClass that creates an instance.
     *
     * @param Expr $expression
     *
     * @return bool
     */
    private function createsInstance(Expr $expression): bool
    {
        if (! $expression instanceof Expr\MethodCall) {
            return false;
        }

        $name = $expression->name;

        return $name instanceof Node\Identifier && in_array($name->toLowerString(), self::INSTANCE_METHODS, true);
    }
}
