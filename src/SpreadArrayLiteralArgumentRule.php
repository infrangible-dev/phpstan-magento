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
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An unpacked array literal as argument that itself unpacks an array, like "new X(...[...$services, $other])".
 *
 * PHPStan counts the elements of the inner array from its shape, PhpStorm counts every element of the literal as one
 * argument and reports "Required parameters '$a, $b' missing". Pass the arguments explicitly, for example after
 * "[$a, $b] = $services;".
 *
 * @implements Rule<Expr>
 */
class SpreadArrayLiteralArgumentRule implements Rule
{
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @param Expr $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Expr\CallLike || $node->isFirstClassCallable()) {
            return [];
        }

        $errors = [];

        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;

            if (! $arg->unpack || ! $value instanceof Expr\Array_) {
                continue;
            }

            // the items of the literal that unpack an array; NodeFinder skips the null items of php-parser 4 (skipped
            // list elements), and the class of the items differs between php-parser 4 and 5
            $items = $value->items;
            $unpackedItem = (new NodeFinder())->findFirst(
                $items,
                static fn(Node $node): bool => in_array($node, $items, true) && property_exists($node, 'unpack') &&
                    $node->unpack === true
            );

            if ($unpackedItem !== null) {
                $errors[] = RuleErrorBuilder::message(
                    'An unpacked array literal with an unpacked array as argument: PhpStorm counts every element of ' .
                    'the literal as one argument and reports required parameters as missing. Pass the arguments ' .
                    'explicitly.'
                )->line($arg->getStartLine())->identifier('infrangible.spreadArrayLiteralArgument')->build();
            }
        }

        return $errors;
    }
}
