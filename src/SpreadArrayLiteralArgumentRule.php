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

            // php-parser 4 has null items for skipped list elements, php-parser 5 has none
            $items = array_filter($value->items, static fn(mixed $item): bool => $item !== null);

            foreach ($items as $item) {
                if ($item->unpack) {
                    $errors[] = RuleErrorBuilder::message(
                        'An unpacked array literal with an unpacked array as argument: PhpStorm counts every ' .
                        'element of the literal as one argument and reports required parameters as missing. Pass the ' .
                        'arguments explicitly.'
                    )->line($arg->getStartLine())->identifier('infrangible.spreadArrayLiteralArgument')->build();

                    break;
                }
            }
        }

        return $errors;
    }
}
