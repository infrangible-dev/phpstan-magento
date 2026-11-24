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
 * A throws tag with ReflectionException on a method of a test class that calls nothing which declares it.
 * PhpStorm reports it as "Exception 'ReflectionException' is never thrown in the function". PHPStan's own check of
 * such tags is switched off for tests in magento.neon, because PHPStan narrows reflection of named classes, which
 * PhpStorm does not; this rule judges like PhpStorm by the declared exceptions of the calls.
 * @implements Rule<CollectedDataNode>
 */
class UnthrownReflectionExceptionRule implements Rule
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
        $throwing = [];

        foreach ($node->get(ReflectionThrowingCallCollector::class) as $methods) {
            foreach ($methods as $method) {
                $throwing[ $method ] = true;
            }
        }

        $errors = [];

        foreach ($node->get(ReflectionThrowsTagCollector::class) as $file => $tags) {
            foreach ($tags as [$method, $line]) {
                if (! isset($throwing[ $method ])) {
                    $errors[] = RuleErrorBuilder::message(
                        sprintf(
                            'ReflectionException is never thrown in %s(). Remove it from the throws tag, PhpStorm ' .
                            'reports "Exception is never thrown in the function".',
                            $method
                        )
                    )->file($file)->line($line)->identifier('infrangible.unthrownReflectionException')->build();
                }
            }
        }

        return $errors;
    }
}
