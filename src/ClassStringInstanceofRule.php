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
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An instanceof check against a parameter whose param tag declares it as class-string, like the param tag
 * "class-string<T> $className" with `$instance instanceof $className`.
 *
 * PhpStorm then types the checked variable as class-string instead of an object of the class and reports for example
 * "Return value is expected to be 'object', '\class-string' returned". Declare the parameter as string in the param tag
 * for PhpStorm and as class-string in the phpstan-param tag for PHPStan (with "@" before "param" and "phpstan-param").
 *
 * @implements Rule<Node\FunctionLike>
 */
class ClassStringInstanceofRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\FunctionLike::class;
    }

    /**
     * @param Node\FunctionLike $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $docComment = $node->getDocComment();

        if ($docComment === null || ! ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_)) {
            return [];
        }

        // only the @param tags that PhpStorm reads, not @phpstan-param or @psalm-param
        preg_match_all(
            '/@param\s+(?:[^\s$]*\|)?class-string\S*\s+\$(\w+)/',
            $docComment->getText(),
            $matches
        );

        if (count($matches[ 1 ]) === 0) {
            return [];
        }

        $classStringParameters = array_flip($matches[ 1 ]);
        $errors = [];

        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->find($node->getStmts() ?? [], fn(Node $found): bool => true) as $instanceof) {
            if (! $instanceof instanceof Expr\Instanceof_) {
                continue;
            }

            $class = $instanceof->class;

            if (! $class instanceof Expr\Variable || ! is_string($class->name) ||
                ! isset($classStringParameters[ $class->name ])) {

                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Parameter $%1$s is declared as class-string in @param and used with instanceof: PhpStorm then ' .
                    'types the checked value as class-string. Declare @param string $%1$s and ' .
                    '@phpstan-param class-string<...> $%1$s.',
                    $class->name
                )
            )->identifier('infrangible.classStringInstanceof')->line($instanceof->getStartLine())->build();
        }

        return $errors;
    }
}
