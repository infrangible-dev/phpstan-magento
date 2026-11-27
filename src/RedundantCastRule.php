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
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * A cast of a value that already has the type of the cast is redundant.
 *
 * PhpStorm reports it as "Type cast is redundant". Like PhpStorm, the declared types count, also those of
 * docblocks. For a call of a method with a body and without native return type, PhpStorm also uses the types that the
 * body returns, which PHPStan does not know: such a cast is only reported if the native type already has the type of
 * the cast, or if every return statement of the method returns a known value of the type (parameters, literals,
 * casts, calls of functions and of methods of the class or its properties, for example Catalog\Helper\Data::
 * getTaxPrice()); else PhpStorm would report a suppression as redundant, for example for
 * Order\Item::getProductOptions(), which declares an array but returns null for items without options. PHP's functions count with their declared
 * types of PhpStorm's stubs, which PHPStan narrows by their arguments: a function whose result can be false or null
 * there (base64_decode() without $strict is string|false, for PHPStan a string) is not reported, see
 * phpstorm-throws.php. If a declared type differs at runtime and
 * PhpStorm still reports the cast, keep it and add "@noinspection PhpCastIsUnnecessaryInspection" with a reason on the
 * line, the line before, the method or the file.
 *
 * @implements Rule<Cast>
 */
class RedundantCastRule implements Rule
{
    private const INSPECTION = 'PhpCastIsUnnecessaryInspection';

    /** @var array<string, list<string>> */
    private array $fileLines = [];

    /** @var array<string, int>|null functions whose result can be false or null, as keys */
    private ?array $nullableReturns = null;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly Parser $parser
    ) {
    }

    public function getNodeType(): string
    {
        return Cast::class;
    }

    /**
     * @param Cast $node
     * @param Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $castType = $this->getCastTypeName($node);

        if ($castType === null) {
            return [];
        }

        $type = $scope->getType($node->expr);

        if (! $this->hasType($node, $type)->yes() || $this->isSuppressed($node, $scope)) {
            return [];
        }

        if (! $this->hasType($node, $scope->getNativeType($node->expr))->yes()) {
            $method = $this->getCalledMethodWithBody($node->expr, $scope);

            if ($method !== null && ! $this->returnsCastType($node, $method, $scope)) {
                return [];
            }
        }

        if ($this->callsNullableFunction($node->expr, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Casting to %s is redundant, the value already has the type %s.',
                    $castType,
                    $type->describe(VerbosityLevel::typeOnly())
                )
            )->identifier('infrangible.redundantCast')->build()
        ];
    }

    /**
     * Returns the name of the type of the cast, or null for casts that are not checked (object, unset).
     *
     * @param Cast $node
     *
     * @return string|null
     */
    private function getCastTypeName(Cast $node): ?string
    {
        return match (true) {
            $node instanceof Cast\Int_ => 'int',
            $node instanceof Cast\Double => 'float',
            $node instanceof Cast\String_ => 'string',
            $node instanceof Cast\Bool_ => 'bool',
            $node instanceof Cast\Array_ => 'array',
            default => null
        };
    }

    /**
     * Checks if the value has the type of the cast.
     *
     * @param Cast $node
     * @param Type $type
     *
     * @return TrinaryLogic
     */
    private function hasType(Cast $node, Type $type): TrinaryLogic
    {
        return match (true) {
            $node instanceof Cast\Int_ => $type->isInteger(),
            $node instanceof Cast\Double => $type->isFloat(),
            $node instanceof Cast\String_ => $type->isString(),
            $node instanceof Cast\Bool_ => $type->isBoolean(),
            default => $type->isArray()
        };
    }

    /**
     * Checks if the expression calls a PHP function whose result can be false or null in the stubs of PhpStorm.
     *
     * @param Expr $expression
     * @param Scope $scope
     *
     * @return bool
     */
    private function callsNullableFunction(Expr $expression, Scope $scope): bool
    {
        if (! $expression instanceof Expr\FuncCall || ! $expression->name instanceof Node\Name ||
            ! $this->reflectionProvider->hasFunction($expression->name, $scope)) {

            return false;
        }

        if ($this->nullableReturns === null) {
            /** @var array{nullable_returns: list<string>} $stubs */
            $stubs = require __DIR__ . '/phpstorm-throws.php';

            $this->nullableReturns = array_flip($stubs[ 'nullable_returns' ]);
        }

        $functionName = strtolower($this->reflectionProvider->getFunction($expression->name, $scope)->getName());

        return isset($this->nullableReturns[ $functionName ]);
    }

    /**
     * Returns the method with a body that the expression calls: not abstract, not declared in an interface and not a
     * magic method of a method tag; null for other expressions.
     *
     * @param Expr $expression
     * @param Scope $scope
     *
     * @return ExtendedMethodReflection|null
     */
    private function getCalledMethodWithBody(Expr $expression, Scope $scope): ?ExtendedMethodReflection
    {
        if (! ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall ||
            $expression instanceof Expr\StaticCall)) {

            return null;
        }

        $name = $expression->name;
        $class = $expression instanceof Expr\StaticCall ? $expression->class : null;

        if (! $name instanceof Node\Identifier) {
            return null;
        }

        $type = $expression instanceof Expr\StaticCall ?
            ($class instanceof Node\Name ? $scope->resolveTypeByName($class) : null) :
            $scope->getType($expression->var);

        if ($type === null || ! $type->hasMethod($name->toString())->yes()) {
            return null;
        }

        $method = $type->getMethod($name->toString(), $scope);
        $abstract = $method->isAbstract();

        return ! $method->getDeclaringClass()->isInterface() &&
            $method->getDeclaringClass()->hasNativeMethod($name->toString()) &&
            ! ($abstract instanceof TrinaryLogic ? $abstract->yes() : $abstract) ? $method : null;
    }

    /**
     * Checks if every return statement of the method returns a value with the type of the cast, like PhpStorm, which
     * also uses the types the body returns. Known are parameters, literals, casts and calls of functions, of methods
     * of the class and of methods of its properties, and min() or max() of known values; any other value counts as
     * unknown.
     *
     * @param Cast $node
     * @param ExtendedMethodReflection $method
     * @param Scope $scope
     *
     * @return bool
     */
    private function returnsCastType(Cast $node, ExtendedMethodReflection $method, Scope $scope): bool
    {
        $class = $method->getDeclaringClass();
        $classMethod = $this->findClassMethod($class, $method->getName());

        if ($classMethod === null) {
            return false;
        }

        $parameterTypes = [];

        foreach ($method->getVariants()[ 0 ]->getParameters() as $parameter) {
            $parameterTypes[ $parameter->getName() ] = $parameter->getType();
        }

        $returns = $this->findReturns($classMethod->stmts ?? []);

        foreach ($returns as $return) {
            $returnType = $return->expr === null ? null :
                $this->resolveType($return->expr, $class, $parameterTypes, $scope);

            if ($returnType === null || ! $this->hasType($node, $returnType)->yes()) {
                return false;
            }
        }

        return count($returns) > 0;
    }

    /**
     * Returns the declaration of the method in the file of the class.
     *
     * @param ClassReflection $class
     * @param string $methodName
     *
     * @return Stmt\ClassMethod|null
     */
    private function findClassMethod(ClassReflection $class, string $methodName): ?Stmt\ClassMethod
    {
        $fileName = $class->getFileName();

        if ($fileName === null) {
            return null;
        }

        $className = $class->getName();

        try {
            $stmts = $this->parser->parseFile($fileName);
        } catch (ParserErrorsException) {
            return null;
        }

        $classLike = (new NodeFinder())->findFirst(
            $stmts,
            fn(Node $found): bool => $found instanceof Stmt\ClassLike && $found->namespacedName !== null &&
                $found->namespacedName->toString() === $className
        );

        return $classLike instanceof Stmt\ClassLike ? $classLike->getMethod($methodName) : null;
    }

    /**
     * Returns the return statements of the statements, without those of nested functions and classes.
     *
     * @param array<Node> $nodes
     *
     * @return list<Stmt\Return_>
     */
    private function findReturns(array $nodes): array
    {
        $returns = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Return_) {
                $returns[] = $node;
            }

            if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->$subNodeName;

                $children = array_filter(
                    is_array($subNode) ? $subNode : [$subNode],
                    fn($child): bool => $child instanceof Node
                );

                $returns = array_merge($returns, $this->findReturns($children));
            }
        }

        return $returns;
    }

    /**
     * Returns the type of a returned expression, null if it is not known.
     *
     * @param Expr $expression
     * @param ClassReflection $class
     * @param array<string, Type> $parameterTypes
     * @param Scope $scope
     *
     * @return Type|null
     */
    private function resolveType(
        Expr $expression,
        ClassReflection $class,
        array $parameterTypes,
        Scope $scope
    ): ?Type {
        $nodeType = $expression->getType();

        if ($expression instanceof Expr\Variable) {
            $variableName = $expression->name;

            return is_string($variableName) ? $parameterTypes[ $variableName ] ?? null : null;
        }

        if (in_array($nodeType, ['Scalar_DNumber', 'Scalar_Float', 'Expr_Cast_Double'], true)) {
            return new FloatType();
        }

        if (in_array($nodeType, ['Scalar_LNumber', 'Scalar_Int', 'Expr_Cast_Int'], true)) {
            return new IntegerType();
        }

        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Cast\String_) {
            return new StringType();
        }

        if ($expression instanceof Cast\Bool_) {
            return new BooleanType();
        }

        if ($expression instanceof Expr\FuncCall && $this->isMinOrMax($expression)) {
            return $this->resolveMinOrMaxType($expression, $class, $parameterTypes, $scope);
        }

        if ($expression instanceof Expr\FuncCall) {
            $functionName = $expression->name;

            return $functionName instanceof Node\Name && $this->reflectionProvider->hasFunction($functionName, null) ?
                $this->reflectionProvider->getFunction($functionName, null)->getVariants()[ 0 ]->getReturnType() :
                null;
        }

        return $expression instanceof Expr\MethodCall ? $this->resolveMethodCallType($expression, $class, $scope) :
            null;
    }

    /**
     * Checks if the expression calls min() or max() with single arguments, whose result PhpStorm types by them.
     *
     * @param Expr\FuncCall $call
     *
     * @return bool
     */
    private function isMinOrMax(Expr\FuncCall $call): bool
    {
        $functionName = $call->name;

        if (! $functionName instanceof Node\Name ||
            ! in_array($functionName->toLowerString(), ['min', 'max'], true) || $call->isFirstClassCallable()) {

            return false;
        }

        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack) {
                return false;
            }
        }

        return count($call->getArgs()) > 1;
    }

    /**
     * Returns the type of a call of min() or max(): the union of the types of its arguments, null if one is not known.
     * PhpStorm types min(float, float) as float, for example in Catalog\Model\Product\Type\Price::getBasePrice().
     *
     * @param Expr\FuncCall $call
     * @param ClassReflection $class
     * @param array<string, Type> $parameterTypes
     * @param Scope $scope
     *
     * @return Type|null
     */
    private function resolveMinOrMaxType(
        Expr\FuncCall $call,
        ClassReflection $class,
        array $parameterTypes,
        Scope $scope
    ): ?Type {
        $types = [];

        foreach ($call->getArgs() as $arg) {
            $type = $this->resolveType($arg->value, $class, $parameterTypes, $scope);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        return TypeCombinator::union(...$types);
    }

    /**
     * Returns the return type of a call of a method of the class or of one of its properties, null if it is not known.
     *
     * @param Expr\MethodCall $methodCall
     * @param ClassReflection $class
     * @param Scope $scope
     *
     * @return Type|null
     */
    private function resolveMethodCallType(Expr\MethodCall $methodCall, ClassReflection $class, Scope $scope): ?Type
    {
        $methodName = $methodCall->name;
        $object = $methodCall->var;

        if (! $methodName instanceof Node\Identifier) {
            return null;
        }

        if ($object instanceof Expr\Variable && $object->name === 'this') {
            $objectType = new ObjectType($class->getName());
        } elseif ($object instanceof Expr\PropertyFetch) {
            $propertyObject = $object->var;
            $propertyName = $object->name;

            if (! $propertyObject instanceof Expr\Variable || $propertyObject->name !== 'this' ||
                ! $propertyName instanceof Node\Identifier || ! $class->hasProperty($propertyName->toString())) {

                return null;
            }

            $objectType = $class->getProperty($propertyName->toString(), $scope)->getReadableType();
        } else {
            return null;
        }

        return $objectType->hasMethod($methodName->toString())->yes() ?
            $objectType->getMethod($methodName->toString(), $scope)->getVariants()[ 0 ]->getReturnType() : null;
    }

    /**
     * Checks if the inspection is suppressed on the line of the cast, the line before, the method or the file.
     *
     * @param Cast $node
     * @param Scope $scope
     *
     * @return bool
     */
    private function isSuppressed(Cast $node, Scope $scope): bool
    {
        $lines = $this->getFileLines($scope->getFile());
        $line = $node->getStartLine();

        foreach ([$line - 1, $line - 2] as $index) {
            if (isset($lines[ $index ]) && $this->suppresses($lines[ $index ])) {
                return true;
            }
        }

        $function = $scope->getFunction();
        $docComment = $function?->getDocComment();

        if ($docComment !== null && $this->suppresses($docComment)) {
            return true;
        }

        foreach ($lines as $fileLine) {
            if (preg_match('/^\s*((abstract|final|readonly)\s+)*(class|interface|trait|enum)\s/', $fileLine) === 1) {
                break;
            }

            if ($this->suppresses($fileLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the text contains a @noinspection tag for redundant casts or for all inspections.
     *
     * @param string $text
     *
     * @return bool
     */
    private function suppresses(string $text): bool
    {
        return preg_match('/@noinspection\s+[^\n]*\b(' . self::INSPECTION . '|ALL)\b/', $text) === 1;
    }

    /**
     * Returns the lines of the file.
     *
     * @param string $file
     *
     * @return list<string>
     */
    private function getFileLines(string $file): array
    {
        if (! isset($this->fileLines[ $file ])) {
            $content = @file_get_contents($file);

            $this->fileLines[ $file ] = $content === false ? [] : explode("\n", $content);
        }

        return $this->fileLines[ $file ];
    }
}
