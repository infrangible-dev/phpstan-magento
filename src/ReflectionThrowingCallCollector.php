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
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use ReflectionException;

/**
 * Collects the methods of test classes that call something whose declared exceptions include ReflectionException,
 * as PhpStorm sees them (declared throws tags, not narrowed by the arguments); calls that cannot be resolved count as
 * throwing. Calls in closures do not count for the method. For UnthrownReflectionExceptionRule.
 * @implements Collector<Expr, string>
 */
class ReflectionThrowingCallCollector implements Collector
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * Returns the calling method as "class::method", null if the expression cannot throw a ReflectionException.
     *
     * @param Expr $node
     * @param Scope $scope
     *
     * @return string|null
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        $class = $scope->getClassReflection();
        $function = $scope->getFunction();

        if ($class === null || $function === null || $scope->isInAnonymousFunction() ||
            ! str_contains($class->getName(), '\\Test\\') || ! $this->canThrow($node, $scope)) {

            return null;
        }

        return $class->getName() . '::' . $function->getName();
    }

    /**
     * Checks if the expression is a call or throw whose declared exceptions can include ReflectionException.
     *
     * @param Expr $node
     * @param Scope $scope
     *
     * @return bool
     */
    private function canThrow(Expr $node, Scope $scope): bool
    {
        if ($node instanceof Expr\Throw_) {
            return $this->includes($scope->getType($node->expr));
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $name = $node->name;
            $type = $scope->getType($node->var);

            if (! $name instanceof Node\Identifier || ! $type->hasMethod($name->toString())->yes()) {
                return true;
            }

            return $this->includes($type->getMethod($name->toString(), $scope)->getThrowType());
        }

        if ($node instanceof Expr\StaticCall) {
            $name = $node->name;
            $className = $node->class;

            if (! $name instanceof Node\Identifier || ! $className instanceof Node\Name) {
                return true;
            }

            $type = $scope->resolveTypeByName($className);

            return ! $type->hasMethod($name->toString())->yes() ||
                $this->includes($type->getMethod($name->toString(), $scope)->getThrowType());
        }

        if ($node instanceof Expr\New_) {
            $className = $node->class;

            if (! $className instanceof Node\Name) {
                return ! $className instanceof Node\Stmt\Class_;
            }

            $resolvedName = $scope->resolveName($className);

            if (! $this->reflectionProvider->hasClass($resolvedName)) {
                return true;
            }

            $classReflection = $this->reflectionProvider->getClass($resolvedName);

            return $classReflection->hasConstructor() &&
                $this->includes($classReflection->getConstructor()->getThrowType());
        }

        if ($node instanceof Expr\FuncCall) {
            $name = $node->name;

            if (! $name instanceof Node\Name || ! $this->reflectionProvider->hasFunction($name, $scope)) {
                return true;
            }

            return $this->includes($this->reflectionProvider->getFunction($name, $scope)->getThrowType());
        }

        return false;
    }

    /**
     * Checks if the declared exceptions include ReflectionException or a subclass; like PhpStorm, a declared parent
     * class like Exception does not count.
     *
     * @param Type|null $throwType
     *
     * @return bool
     */
    private function includes(?Type $throwType): bool
    {
        if ($throwType === null) {
            return false;
        }

        foreach ($throwType->getObjectClassNames() as $className) {
            if ($className === ReflectionException::class || ($this->reflectionProvider->hasClass($className) &&
                    in_array(
                        ReflectionException::class,
                        $this->reflectionProvider->getClass($className)->getParentClassesNames(),
                        true
                    ))) {

                return true;
            }
        }

        return false;
    }
}
