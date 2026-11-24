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
use PHPStan\Collectors\Collector;

/**
 * Collects the methods of test classes whose docblock declares ReflectionException in a throws tag, for
 * UnthrownReflectionExceptionRule.
 * @implements Collector<ClassMethod, array{string, int}>
 */
class ReflectionThrowsTagCollector implements Collector
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * Returns the method as "class::method" and the line of its throws tag, null for other methods.
     *
     * @param ClassMethod $node
     * @param Scope $scope
     *
     * @return array{string, int}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();
        $docComment = $node->getDocComment();

        if ($class === null || $docComment === null || ! str_contains($class->getName(), '\\Test\\')) {
            return null;
        }

        $text = $docComment->getText();

        if (preg_match('/@throws\s+\\\\?ReflectionException\b/', $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            $class->getName() . '::' . $node->name->toString(),
            $docComment->getStartLine() + substr_count(substr($text, 0, $matches[ 0 ][ 1 ]), "\n")
        ];
    }
}
