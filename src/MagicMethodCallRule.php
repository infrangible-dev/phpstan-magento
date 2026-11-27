<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A call of a method that a DataObject does not declare and that its magic __call() handles, also if a @method tag
 * declares it, for example getUrlKey() of a product. Magento derives the key of the data from the method name, which
 * changed between versions, and returns null for unknown keys without a warning. Use getData() and setData() with the
 * key, for example getData('foo_bar').
 * @implements Rule<MethodCall>
 */
class MagicMethodCallRule implements Rule
{
    /** The class whose __call() derives the key of the data from the method name. */
    private const DATA_OBJECT = 'Magento\\Framework\\DataObject';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name;

        if (! $name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $name->toString();
        $method = $scope->getMethodReflection($scope->getType($node->var), $methodName);

        // undefined methods are reported by PHPStan itself, the methods of PHPUnit's test double interfaces are declared
        if ($method === null) {
            return [];
        }

        $declaringClass = $method->getDeclaringClass();
        $nativeClass = $declaringClass->getNativeReflection();

        if ($nativeClass->hasMethod($methodName) || ! $nativeClass->hasMethod('__call') ||
            ! in_array(
                self::DATA_OBJECT,
                [$declaringClass->getName(), ...$declaringClass->getParentClassesNames()],
                true
            )) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Method %s::%s() is a magic method of __call(), whose key Magento derives from the method name ' .
                    'and which returns null for unknown keys. Use getData() or setData() with the key.',
                    $declaringClass->getName(),
                    $methodName
                )
            )->identifier('infrangible.magicMethodCall')->build()
        ];
    }
}
