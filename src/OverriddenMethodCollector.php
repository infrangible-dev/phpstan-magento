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
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;

/**
 * Collects the methods of parent classes that a method overrides, for UnusedParameterRule and
 * RedundantUnusedParameterSuppressionRule: PhpStorm reports no unused parameters of methods that a subclass overrides,
 * the subclass may use them.
 *
 * @implements Collector<InClassMethodNode, list<string>>
 */
class OverriddenMethodCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * Returns the overridden methods as "class::method" in lowercase, null if the method overrides none.
     *
     * @param InClassMethodNode $node
     * @param Scope $scope
     *
     * @return list<string>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();
        $methodName = $node->getOriginalNode()->name->toString();

        if ($class === null || strtolower($methodName) === '__construct') {
            return null;
        }

        $methods = [];

        foreach ($class->getParents() as $parent) {
            if ($parent->hasNativeMethod($methodName)) {
                $methods[] = self::getKey($parent->getName(), $methodName);
            }
        }

        return count($methods) > 0 ? $methods : null;
    }

    /**
     * Returns the key of a method: "class::method" in lowercase.
     *
     * @param string $className
     * @param string $methodName
     *
     * @return string
     */
    public static function getKey(string $className, string $methodName): string
    {
        return strtolower(ltrim($className, '\\') . '::' . $methodName);
    }

    /**
     * Returns the keys of all overridden methods of the collected data.
     *
     * @param array<string, list<list<string>>> $collectedData the data of this collector by file
     *
     * @return array<string, true>
     */
    public static function getOverriddenMethods(array $collectedData): array
    {
        $methods = [];

        foreach ($collectedData as $fileData) {
            foreach ($fileData as $keys) {
                foreach ($keys as $key) {
                    $methods[ $key ] = true;
                }
            }
        }

        return $methods;
    }
}
