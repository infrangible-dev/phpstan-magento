# Changelog

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
