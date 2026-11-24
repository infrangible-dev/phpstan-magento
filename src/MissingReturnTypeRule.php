<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method without native return type, also in interfaces and traits; a return tag in the docblock is not enough.
 * PhpStorm reports it as "Missing function's return type declaration". Constructors, destructors and __clone() have no
 * return type, and methods that return a resource (only declared in the docblock, PHP has no type for it) are not
 * reported. A method that overrides an untyped method of Magento may declare a return type, PHP allows it.
 * @implements Rule<ClassMethod>
 */
class MissingReturnTypeRule implements Rule
{
    /** Methods that cannot declare a return type. */
    private const WITHOUT_RETURN_TYPE = ['__construct', '__destruct', '__clone'];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @param ClassMethod $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name->toString();

        if ($node->returnType !== null || in_array(strtolower($name), self::WITHOUT_RETURN_TYPE, true)) {
            return [];
        }

        $docComment = $node->getDocComment();

        if ($docComment !== null &&
            preg_match('/@(?:phpstan-|psalm-)?return\s+[^\n]*\bresource\b/i', $docComment->getText()) === 1) {

            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Method %s() has no native return type. Declare it, PhpStorm reports "Missing function\'s ' .
                    'return type declaration".',
                    $name
                )
            )->identifier('infrangible.missingReturnType')->build()
        ];
    }
}
