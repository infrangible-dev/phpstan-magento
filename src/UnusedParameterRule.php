<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A parameter of a method that the method does not use.
 *
 * PhpStorm reports it as "Unused parameter '$x'. The parameter's value is not used anywhere.", except for methods that
 * override or implement a method of a parent class or an interface, or that a subclass overrides, whose signature is
 * given. Methods with a given signature that PhpStorm does not know, like the before, after and around methods of
 * Magento plugins, need "@noinspection PhpUnusedParameterInspection" in their docblock. The parameters are collected by
 * UnusedParameterCollector, the overriding methods by OverriddenMethodCollector.
 *
 * @implements Rule<CollectedDataNode>
 */
class UnusedParameterRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $overriddenMethods = OverriddenMethodCollector::getOverriddenMethods(
            $node->get(OverriddenMethodCollector::class)
        );
        $errors = [];

        foreach ($node->get(UnusedParameterCollector::class) as $file => $fileData) {
            foreach ($fileData as $parameters) {
                foreach ($parameters as [$methodKey, $methodName, $parameterName, $line]) {
                    if (isset($overriddenMethods[ $methodKey ])) {
                        continue;
                    }

                    $errors[] = RuleErrorBuilder::message(
                        sprintf(
                            'Parameter $%s of %s() is not used. Remove it, or add "@noinspection %s" to the docblock ' .
                            'if the signature is given (for example a method of a plugin).',
                            $parameterName,
                            $methodName,
                            UnusedParameterCollector::INSPECTION
                        )
                    )->file($file)->line($line)->identifier('infrangible.unusedParameter')->build();
                }
            }
        }

        return $errors;
    }
}
