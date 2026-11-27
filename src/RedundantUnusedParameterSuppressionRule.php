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
 * A suppression of unused parameters on a method that cannot have an unused parameter in the sense of PhpStorm.
 *
 * PhpStorm does not report unused parameters of methods that override or implement a method of a parent class or an
 * interface, or that a subclass overrides (the signature is given), and of course not if the method uses all its
 * parameters. A "@noinspection PhpUnusedParameterInspection" in the docblock of such a method is reported by PhpStorm
 * as "Redundant suppression". The suppressions are collected by RedundantUnusedParameterSuppressionCollector, the
 * overriding methods by OverriddenMethodCollector.
 *
 * @implements Rule<CollectedDataNode>
 */
class RedundantUnusedParameterSuppressionRule implements Rule
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

        foreach ($node->get(RedundantUnusedParameterSuppressionCollector::class) as $file => $suppressions) {
            foreach ($suppressions as [$methodKey, $methodName, $reason, $line]) {
                if ($reason === null && isset($overriddenMethods[ $methodKey ])) {
                    $reason = 'is overridden by a subclass';
                }

                if ($reason === null) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'The suppression of %s is redundant: %s() %s, PhpStorm reports no unused parameter.',
                        UnusedParameterCollector::INSPECTION,
                        $methodName,
                        $reason
                    )
                )->file($file)->line($line)->identifier('infrangible.redundantUnusedParameterSuppression')->build();
            }
        }

        return $errors;
    }
}
