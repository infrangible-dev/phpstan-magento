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
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method of a test double of a generated extension attributes interface (like ProductOptionExtensionInterface) that
 * is configured with method(). Magento generates these interfaces with the extension attributes of the configuration
 * of the installed modules; the bootstrap of the unit tests generates them without it, so the method only exists in an
 * installation and the test fails in CI with "Trying to configure method ... which cannot be configured because it
 * does not exist". Use a plain object with the methods instead.
 *
 * @implements Rule<Expr\MethodCall>
 */
class GeneratedExtensionTestDoubleRule implements Rule
{
    private const EXTENSION_ATTRIBUTES_INTERFACE = 'Magento\Framework\Api\ExtensionAttributesInterface';

    /**
     * @param ReflectionProvider $reflectionProvider
     */
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Expr\MethodCall::class;
    }

    /**
     * @param Expr\MethodCall $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name;

        if (! $name instanceof Node\Identifier || $name->toLowerString() !== 'method') {
            return [];
        }

        foreach ($scope->getType($node->var)->getObjectClassNames() as $className) {
            if (! $this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $class = $this->reflectionProvider->getClass($className);

            // the interfaces of an interface are its parents, so ExtensionAttributesInterface itself does not match
            if ($class->isInterface() &&
                array_key_exists(self::EXTENSION_ATTRIBUTES_INTERFACE, $class->getInterfaces())) {

                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'A method of a test double of the generated interface %s is configured: the unit test ' .
                            'bootstrap generates it without the extension attributes, so the method does not exist ' .
                            'in CI. Use a plain object with the methods instead.',
                            $class->getName()
                        )
                    )->identifier('infrangible.generatedExtensionTestDouble')->build()
                ];
            }
        }

        return [];
    }
}
