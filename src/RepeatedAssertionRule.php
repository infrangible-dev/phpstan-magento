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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An assertion of the result of a method call that repeats an earlier assertion of the same call, without any use of
 * the object in between.
 *
 * PhpStorm assumes that a method returns the same value as long as nothing is done with its object, and reports
 * "Assertion is always 'false' because '$this->assertTrue($object->isX())' is already 'true' at this point", even if
 * the state was changed through another object (for example a helper that holds the object). Assert the state through
 * the object that changed it instead.
 *
 * @implements Rule<Stmt\ClassMethod>
 */
class RepeatedAssertionRule implements Rule
{
    private const ASSERTIONS = ['asserttrue', 'assertfalse', 'assertnull', 'assertnotnull'];

    public function getNodeType(): string
    {
        return Stmt\ClassMethod::class;
    }

    /**
     * @param Stmt\ClassMethod $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $printer = new Standard();
        $nodeFinder = new NodeFinder();
        /** @var array<string, string> $asserted printed call => name of the variable */
        $asserted = [];
        $errors = [];

        foreach ($node->stmts ?? [] as $statement) {
            $call = $this->getAssertedCall($statement);

            if ($call !== null && $call->var instanceof Expr\Variable && is_string($call->var->name)) {
                $key = $printer->prettyPrintExpr($call);

                if (array_key_exists($key, $asserted)) {
                    $errors[] = RuleErrorBuilder::message(
                        sprintf(
                            'PhpStorm reports this assertion as always true or false: %s was already asserted, ' .
                            'and $%s is not used in between. Assert the state through the object that changed it.',
                            $key,
                            $call->var->name
                        )
                    )->identifier('infrangible.repeatedAssertion')->line($statement->getStartLine())->build();
                }

                $asserted[$key] = $call->var->name;

                continue;
            }

            $used = [];

            foreach ($nodeFinder->find($statement, static fn(Node $found): bool => $found instanceof Expr\Variable)
                     as $variable) {

                if ($variable instanceof Expr\Variable && is_string($variable->name)) {
                    $used[$variable->name] = true;
                }
            }

            $asserted = array_filter($asserted, static fn(string $name): bool => ! isset($used[$name]));
        }

        return $errors;
    }

    /**
     * Returns the method call of a statement like $this->assertTrue($object->method()).
     */
    private function getAssertedCall(Stmt $statement): ?Expr\MethodCall
    {
        if (! $statement instanceof Stmt\Expression) {
            return null;
        }

        $assertion = $statement->expr;

        if (! ($assertion instanceof Expr\MethodCall || $assertion instanceof Expr\StaticCall) ||
            ! $assertion->name instanceof Node\Identifier ||
            ! in_array($assertion->name->toLowerString(), self::ASSERTIONS, true)) {

            return null;
        }

        $argument = $assertion->getArgs()[0] ?? null;

        return $argument !== null && $argument->value instanceof Expr\MethodCall ? $argument->value : null;
    }
}
