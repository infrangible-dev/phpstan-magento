# Changelog

## 1.2.15

### Added

- `ClassStringInstanceofRule`: an `instanceof` check against a parameter whose `@param` tag declares it as
  `class-string`, like `@param class-string<T> $className` with `$instance instanceof $className`. PhpStorm then types
  the checked value as class-string instead of an object of the class and reports "Return value is expected to be
  'object', '\class-string' returned". Declare the parameter as `string` in `@param` and as class-string in
  `@phpstan-param`.

## 1.2.14

### Added

- `SameParameterValueRule`: a parameter of a private method that all calls of the method pass the same constant value,
  like `createResource([7])` in every call. PhpStorm reports "Parameter's value is always '[7]'"; the value belongs
  into the method. Methods that are also used as callables or named in strings are skipped.

## 1.2.13

### Added

- `VariableClassTestDoubleCallRule`: a method of the tested class called on a test double of PHPUnit whose class is
  given by a variable, like `$this->getMockBuilder($className)->getMock()->setTemplate('x')`. PHPStan takes the class
  from the `class-string` type of the variable, PhpStorm only knows the test double interface and reports "Method
  'setTemplate' not found in MockObject". `method()` and `expects()` of the test double interfaces stay allowed.
- `MagicMethodCallRule`: a method of a `DataObject` that only its magic `__call()` handles, also if a `@method` tag
  declares it, like `Product::getUrlKey()`. Magento derives the key of the data from the method name, which changed
  between versions, and returns null for unknown keys without a warning; use `getData()` and `setData()` with the
  column name.

## 1.2.12

### Changed

- `RedundantCastRule`: `min()` and `max()` returned by a called method have the types of their arguments, like
  PhpStorm types them, so a cast of `Catalog\Model\Product\Type\Price::getBasePrice()` to float is reported ("Type
  cast is redundant").

## 1.2.11

### Added

- `GeneratedExtensionTestDoubleRule`: a method of a test double of a generated extension attributes interface, like
  `ProductOptionExtensionInterface`, configured with `method()`. The unit test bootstrap of Magento generates these
  interfaces without the extension attributes of the configuration, so such tests pass in an installation and fail in
  CI.
- `MockObjectWithoutExpectationsRule`: a test class that creates mock objects of PHPUnit without configuring any
  expectation and without the attribute `AllowMockObjectsWithoutExpectations`. PHPUnit 12 reports a notice for every
  such test, so the tests pass with older versions and fail in CI with PHP 8.5.

## 1.2.10

### Added

- `SpreadArrayLiteralArgumentRule`: an unpacked array literal as argument that itself unpacks an array, like
  `new X(...[...$services, $other])`. PHPStan counts the arguments from the shape of the inner array, PhpStorm counts
  every element of the literal as one argument and reports "Required parameters '$a, $b' missing".

## 1.2.9

### Fixed

- `UnusedParameterRule` and `RedundantUnusedParameterSuppressionRule`: methods that a subclass in the analysed code
  overrides have a given signature, like PhpStorm judges them. Their unused parameters are no longer reported, and
  `@noinspection PhpUnusedParameterInspection` on them is reported as redundant ("Redundant suppression" in
  PhpStorm). Both rules evaluate the data of the new collectors `OverriddenMethodCollector`,
  `UnusedParameterCollector` and `RedundantUnusedParameterSuppressionCollector`.

## 1.2.8

### Changed

- Supports PHP 8.1 again, like Magento 2.4.6: the rules declare their properties `readonly` instead of being
  `readonly` classes (PHP 8.2). The Adobe Commerce Marketplace rejects modules that allow fewer PHP versions than the
  Magento versions they support.

## 1.2.7

### Added

- `TemplateTestDoubleReturnRule`: a test double of PHPUnit (`createStub()`, `createMock()` and the like) returned by a
  method whose return tag is one of its own template types, which PhpStorm reports as "Return type does not match the
  declared".

## 1.2.6

### Fixed

- `hasSpecialPrice()` of the price box of configurable products declares `Exception` with all Magento versions
  (`stubs/ConfigurableFinalPriceBox.stub`); Magento declares it since 2.4.8, so the tag that modules need there was
  reported as not thrown with 2.4.6 and 2.4.7.

## 1.2.5

### Fixed

- The parent item of quote items (`AbstractItem::getParentItem()`) is nullable (`stubs/AbstractItem.stub`); Magento
  declares it as always set, so checks of items without parent were reported as always false.

## 1.2.4

### Added

- `ReflectionInstanceReturnRule`: an instance created by reflection that a function with a class as native return
  type returns, which PhpStorm types as `object|string` and reports as "Return value is expected to be 'X',
  'object|string' returned".

## 1.2.3

### Added

- `UnthrownReflectionExceptionRule`: `ReflectionException` in the throws tag of a test method that calls nothing which
  declares it, which PhpStorm reports as "Exception 'ReflectionException' is never thrown in the function". PHPStan's
  own check stays off for tests, because it narrows reflection of named classes.

## 1.2.2

### Fixed

- `RedundantCastRule` did not report casts of calls of methods with a body whose return statements all return a value
  of the type, for example `(float)` of `Magento\Catalog\Helper\Data::getTaxPrice()`, which PhpStorm reports as
  redundant. It reads the method and checks parameters, literals, casts and calls with declared types; other values
  still count as unknown.

## 1.2.1

### Added

- `OverwrittenAssignmentRule`: an assignment to a variable whose value is overwritten before it is read, which
  PhpStorm reports as "Unused local variable. The value of the variable is overwritten immediately".

## 1.2.0

### Added

- `MissingReturnTypeRule`: a method without native return type, which PhpStorm reports as "Missing function's return
  type declaration". Constructors, destructors, `__clone()` and methods that return a resource are not reported.

## 1.1.1

### Fixed

- `RedundantCastRule` did not report casts of the result of magic methods of `@method` tags, for example
  `(bool)$dataObject->getResult()` with `@method bool getResult()`, which PhpStorm reports as redundant.

## 1.1.0

### Added

- `RepeatedAssertionRule`: an assertion of a method call that repeats an earlier assertion of the same call without
  any use of the object in between, which PhpStorm reports as always true or false.

## 1.0.0

First release: the PHPStan configuration and rules that the Infrangible Magento modules had as copies of the
templates of [infrangible-dev/workflows](https://github.com/infrangible-dev/workflows).

### Changed (compared to the copies)

- The rules are loaded by the autoloader of Composer and registered in `rules.neon`, instead of `phpstan.rules.php`
  with `require_once`.
- Extension attributes are generated from the `etc/extension_attributes.xml` files of all installed modules, also of
  modules with their code in `src` (for example other Infrangible modules), instead of the module of the analysis only.
- Tests are recognized by their namespace (`\Test\`) instead of the path `src/Test`, which would be relative to the
  configuration of the package.
