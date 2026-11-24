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
 * An assignment to a variable whose value is overwritten before it is read.
 * PhpStorm reports it as "Unused local variable. The value of the variable is overwritten immediately". Reported are
 * two cases: the next statement that uses the variable assigns it without reading it first, or the variable is first
 * assigned by a statement of a loop body and not used after the loop (the loop never reads the first value). Functions
 * with references, compact(), extract(), get_defined_vars() or variable variables are skipped.
 * @implements Rule<FunctionLike>
 */
class OverwrittenAssignmentRule implements Rule
{
    /** Functions that access variables by name. */
    private const VARIABLE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars'];

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
        $stmts = $stmts === null ? null : array_values($stmts);

        if ($stmts === null || $this->hasDynamicVariables($stmts)) {
            return [];
        }

        $errors = [];

        foreach ($this->getStatementLists($stmts) as $statementList) {
            foreach ($this->checkStatements($statementList) as $line => $name) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'The value assigned to $%s is overwritten before it is read. Remove the assignment, PhpStorm ' .
                        'reports "The value of the variable is overwritten immediately".',
                        $name
                    )
                )->line($line)->identifier('infrangible.overwrittenAssignment')->build();
            }
        }

        return $errors;
    }

    /**
     * Returns the assignments of the statements whose value is overwritten before it is read, as line => variable.
     *
     * @param list<Stmt> $stmts
     *
     * @return array<int, string>
     */
    private function checkStatements(array $stmts): array
    {
        $found = [];

        foreach ($stmts as $index => $stmt) {
            $name = $this->getAssignedVariable($stmt);

            if ($name === null) {
                continue;
            }

            $rest = array_slice($stmts, $index + 1);

            foreach ($rest as $restIndex => $next) {
                if (! $this->uses($next, $name)) {
                    continue;
                }

                if ($this->getAssignedVariable($next) === $name ||
                    ($this->overwritesInLoop($next, $name) &&
                        ! $this->usesAny(array_slice($rest, $restIndex + 1), $name))) {

                    $found[ $stmt->getStartLine() ] = $name;
                }

                break;
            }
        }

        return $found;
    }

    /**
     * Checks if the statement is a loop that does not read the variable in its head and assigns it in the first
     * statement of its body that uses it.
     *
     * @param Stmt $stmt
     * @param string $name
     *
     * @return bool
     */
    private function overwritesInLoop(Stmt $stmt, string $name): bool
    {
        if ($stmt instanceof Stmt\Foreach_) {
            if ($this->uses($stmt->expr, $name) || $this->usesNullable($stmt->keyVar, $name) ||
                $this->uses($stmt->valueVar, $name)) {

                return false;
            }
        } elseif ($stmt instanceof Stmt\For_) {
            if ($this->usesAny($stmt->init, $name) || $this->usesAny($stmt->cond, $name) ||
                $this->usesAny($stmt->loop, $name)) {

                return false;
            }
        } elseif ($stmt instanceof Stmt\While_ || $stmt instanceof Stmt\Do_) {
            if ($this->uses($stmt->cond, $name)) {
                return false;
            }
        } else {
            return false;
        }

        foreach ($stmt->stmts as $bodyStmt) {
            if ($this->uses($bodyStmt, $name)) {
                return $this->getAssignedVariable($bodyStmt) === $name;
            }
        }

        return false;
    }

    /**
     * Returns the name of the variable that the statement assigns, if the assigned value does not use it.
     *
     * @param Stmt $stmt
     *
     * @return string|null
     */
    private function getAssignedVariable(Stmt $stmt): ?string
    {
        if (! $stmt instanceof Stmt\Expression) {
            return null;
        }

        $assign = $stmt->expr;

        if (! $assign instanceof Expr\Assign) {
            return null;
        }

        $variable = $assign->var;

        if (! $variable instanceof Expr\Variable) {
            return null;
        }

        $name = $variable->name;

        return ! is_string($name) || $name === 'this' || $this->uses($assign->expr, $name) ? null : $name;
    }

    /**
     * Checks if the node uses the variable, also in closures that take it.
     *
     * @param Node $node
     * @param string $name
     *
     * @return bool
     */
    private function uses(Node $node, string $name): bool
    {
        return (new NodeFinder())->findFirst(
            $node,
            fn(Node $found): bool => ($found instanceof Expr\Variable && $found->name === $name) ||
                ($found instanceof Expr\Closure && $this->closureTakes($found, $name, false))
        ) !== null;
    }

    /**
     * Checks if the node, if any, uses the variable.
     *
     * @param Node|null $node
     * @param string $name
     *
     * @return bool
     */
    private function usesNullable(?Node $node, string $name): bool
    {
        return $node !== null && $this->uses($node, $name);
    }

    /**
     * Checks if one of the nodes uses the variable.
     *
     * @param array<Node> $nodes
     * @param string $name
     *
     * @return bool
     */
    private function usesAny(array $nodes, string $name): bool
    {
        foreach ($nodes as $node) {
            if ($this->uses($node, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all lists of statements of the function body, also of nested blocks, without nested functions.
     *
     * @param list<Stmt> $stmts
     *
     * @return list<list<Stmt>>
     */
    private function getStatementLists(array $stmts): array
    {
        $lists = [$stmts];

        foreach ($stmts as $stmt) {
            foreach ($stmt->getSubNodeNames() as $subNodeName) {
                $subNode = $stmt->$subNodeName;

                $children = is_array($subNode) ? $subNode : [$subNode];

                if (count($children) > 0 && array_is_list($children) && $this->isStatementList($children)) {
                    /** @var list<Stmt> $children */
                    $lists = array_merge($lists, $this->getStatementLists($children));
                }

                foreach ($children as $child) {
                    if (($child instanceof Stmt\ElseIf_ || $child instanceof Stmt\Else_ ||
                            $child instanceof Stmt\Catch_ || $child instanceof Stmt\Finally_ ||
                            $child instanceof Stmt\Case_) && count($child->stmts) > 0) {

                        $lists = array_merge($lists, $this->getStatementLists(array_values($child->stmts)));
                    }
                }
            }
        }

        return $lists;
    }

    /**
     * Checks if all values are statements that are no nested functions or classes.
     *
     * @param array<int|string, mixed> $values
     *
     * @return bool
     */
    private function isStatementList(array $values): bool
    {
        foreach ($values as $value) {
            if (! $value instanceof Stmt || $value instanceof Stmt\Function_ || $value instanceof Stmt\ClassLike ||
                $value instanceof Stmt\ElseIf_ || $value instanceof Stmt\Else_ || $value instanceof Stmt\Catch_ ||
                $value instanceof Stmt\Finally_ || $value instanceof Stmt\Case_) {

                return false;
            }
        }

        return true;
    }

    /**
     * Checks if the closure takes the variable (any variable without name), only by reference if asked.
     *
     * @param Expr\Closure $closure
     * @param string|null $name
     * @param bool $byRef
     *
     * @return bool
     */
    private function closureTakes(Expr\Closure $closure, ?string $name, bool $byRef): bool
    {
        foreach ($closure->uses as $use) {
            if (($name === null || $use->var->name === $name) && (! $byRef || $use->byRef)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the statements use references, variable variables or functions that access variables by name.
     *
     * @param list<Stmt> $stmts
     *
     * @return bool
     */
    private function hasDynamicVariables(array $stmts): bool
    {
        return (new NodeFinder())->findFirst($stmts, fn(Node $node): bool => $this->isDynamicVariable($node)) !== null;
    }

    /**
     * Checks if the node is a reference, a variable variable or a call of a function that accesses variables by name.
     *
     * @param Node $node
     *
     * @return bool
     */
    private function isDynamicVariable(Node $node): bool
    {
        if ($node instanceof Expr\Variable) {
            return ! is_string($node->name);
        }

        if ($node instanceof Expr\FuncCall) {
            $functionName = $node->name;

            return $functionName instanceof Node\Name &&
                in_array($functionName->toLowerString(), self::VARIABLE_FUNCTIONS, true);
        }

        return $node instanceof Expr\AssignRef || $node instanceof Stmt\Global_ || $node instanceof Stmt\Static_ ||
            ($node instanceof Expr\Closure && $this->closureTakes($node, null, true)) ||
            ($node instanceof Stmt\Foreach_ && $node->byRef);
    }
}
