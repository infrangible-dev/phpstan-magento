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
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A test class that creates mock objects of PHPUnit (createMock(), getMock() of the mock builder and the like) without
 * configuring any expectation (expects() or with()) and without the attribute AllowMockObjectsWithoutExpectations.
 * PHPUnit 12, which the CI uses with PHP 8.5, reports a notice for every such test ("No expectations were configured
 * for the mock object"), older versions do not. Use createStub() or add the attribute to the class.
 *
 * @implements Rule<InClassNode>
 */
class MockObjectWithoutExpectationsRule implements Rule
{
    // in lowercase: PHPUnit is not installed with the rules, and Rector turns existing class names into ::class
    private const TEST_CASE = 'phpunit\framework\testcase';

    // a text: the attribute only exists in newer versions of PHPUnit
    private const ATTRIBUTE = 'PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations';

    /** The methods that create mock objects, in lowercase. */
    private const MOCK_METHODS = [
        'createmock',
        'createpartialmock',
        'createconfiguredmock',
        'createmockforintersectionofinterfaces',
        'getmock',
        'getmockforabstractclass'
    ];

    /** The methods that configure expectations, in lowercase. */
    private const EXPECTATION_METHODS = ['expects', 'with', 'withanyparameters'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        $parentNames = array_map(
            static fn(ClassReflection $parent): string => strtolower($parent->getName()),
            $class->getParents()
        );

        if (! in_array(self::TEST_CASE, $parentNames, true) || $this->hasAttribute($node)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $calls = $nodeFinder->find(
            $node->getOriginalNode()->stmts,
            static fn(Node $found): bool => $found instanceof Expr\MethodCall || $found instanceof Expr\StaticCall
        );

        $firstMockCall = null;

        foreach ($calls as $call) {
            $name = $call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall ? $call->name : null;

            if (! $name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $name->toLowerString();

            if (in_array($methodName, self::EXPECTATION_METHODS, true)) {
                return [];
            }

            if ($firstMockCall === null && in_array($methodName, self::MOCK_METHODS, true)) {
                $firstMockCall = $call;
            }
        }

        if ($firstMockCall === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'A mock object without any expectation: PHPUnit 12 reports a notice for every such test. Use ' .
                'createStub() or add the attribute #[AllowMockObjectsWithoutExpectations] to the class.'
            )->line($firstMockCall->getStartLine())->identifier('infrangible.mockObjectWithoutExpectations')->build()
        ];
    }

    /**
     * Checks if the class has the attribute AllowMockObjectsWithoutExpectations.
     *
     * @param InClassNode $node
     *
     * @return bool
     */
    private function hasAttribute(InClassNode $node): bool
    {
        foreach ($node->getOriginalNode()->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if (ltrim($attribute->name->toString(), '\\') === self::ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }
}
