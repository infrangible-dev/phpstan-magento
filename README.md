# PHPStan for Magento modules

PHPStan configuration and rules of the [Infrangible](https://www.infrangible.dev) Magento 2 modules. They make PHPStan
report what PhpStorm reports, so that a module that passes PHPStan in CI also has no findings in PhpStorm, and they
let PHPStan analyse the classes that Magento generates.

- `magento.neon`: the configuration of the modules: level 9, the classes that Magento generates, the differences
  between the Magento versions and `rules.neon`
- `rules.neon`: the rules for the conventions of PhpStorm and the `@throws` tags as PhpStorm expects them, also usable
  without Magento

Supports Magento 2.4.6 to 2.4.9 (PHPStan 1.12, which Magento comes with) and PHPStan 2, with PHP 8.2 or newer.

## Usage

Require the package in `require-dev` of the module:

```json
"require-dev": {
    "infrangible/phpstan-magento": "~1.0.0"
}
```

Composer does not install `require-dev` of a required package, so the package has to be installed in the Magento
installation in which the module is checked: the checks of
[infrangible-dev/workflows](https://github.com/infrangible-dev/workflows) do it with the version of the module's
`require-dev`, the Anodosys development environment merges `require-dev` of the module into the installation.

`phpstan.neon` of the module includes `magento.neon` from the Magento installation next to the module (`/var/www/module`
and `/var/www/magento` in the development environment, `module` and `magento` in the checks of the workflows) and adds
its paths:

```neon
includes:
    - ../magento/vendor/infrangible/phpstan-magento/magento.neon
parameters:
    paths:
        - src
```

The Magento installation is taken from the environment variable `MAGENTO_ROOT`, by default `/var/www/magento`. PHPStan
runs from the installation:

```bash
cd /var/www/magento && vendor/bin/phpstan analyse -c /var/www/module/phpstan.neon
```

## Configuration (magento.neon)

- level 9, the `@throws` tags as PhpStorm expects them (missing and too wide tags; unchecked are `Error`,
  `LogicException`, `RuntimeException`, `PHPUnit\Exception` and Magento's `Zend_Db_Select_Exception`) and typed
  properties that are read before they are set (`setUp()` of tests and Magento's `_construct()` count as constructors)
- `bootstrap.php` loads the PHPStan autoloader of Magento and generates factories with Magento's own generator, so that
  `create()` returns the created class, and extension attributes (`OrderExtensionInterface`, ...) with the methods of
  the attributes that the `etc/extension_attributes.xml` files of Magento and the installed modules declare (also
  modules in `app/code` and modules with their code in `src`)
- `versions.php` adds what depends on the Magento version of the installation: before 2.4.8,
  `AbstractModel::_init()` and `load()` do not declare their `LocalizedException`, so a tag that 2.4.8 needs is not
  reported as too wide there; before 2.4.9, `Escaper::escapeHtml()` declares `string|array` as result also for a
  string, `stubs/Escaper.stub` declares the types of 2.4.9; with all versions, `stubs/AbstractItem.stub` declares the
  parent item of quote items as nullable (Magento declares it as always set, items without parent return null) and
  `stubs/ConfigurableFinalPriceBox.stub` the `Exception` of `hasSpecialPrice()` of configurable price boxes, which
  Magento declares since 2.4.8
- tests may declare `@throws ReflectionException` for reflection on classes they name themselves (classes in a
  namespace `Test`), which PhpStorm requires and PHPStan knows cannot be thrown; attributes of newer PHPUnit versions
  (`DataProvider`, `AllowMockObjectsWithoutExpectations`) are not reported with older ones

## Rules (rules.neon)

Each rule reports what a PhpStorm inspection reports, with the message of PhpStorm in quotes:

- `DefaultArgumentRule`: a literal as last argument that is the default value of the parameter ("Argument matches the
  parameter's default value")
- `OwnThrowsRule`: checked exceptions have to be in the `@throws` tag of the method itself, also when a parent method
  declares them (PHPStan inherits the tag, PhpStorm does not, for example in constructors of Magento models)
- `NeverThrownCatchRule`: a catch of a checked exception that no call of the try block declares ("Exception is never
  thrown in the corresponding try block"; not for `Exception` and `Throwable`, and not with
  `@noinspection PhpRedundantCatchClauseInspection` on the method or the try)
- `RedundantCastRule`: a cast of a value that already has the type of the cast ("Type cast is redundant"; types of
  docblocks count, as in PhpStorm, except for calls of methods with a body and without native return type, whose
  returned types PhpStorm also reads: these are reported only if every return statement returns a known value of the
  type (parameters, literals, casts, calls with declared types), and except for PHP functions whose result can be false or null in the stubs of
  PhpStorm, which PHPStan narrows; where PhpStorm still reports a needed cast, keep it with
  `@noinspection PhpCastIsUnnecessaryInspection`)
- `OnlyImplementationInstanceofRule`: an `instanceof` check of a value whose declared type is an interface or abstract
  class of the module against the only class of the module that implements it, also with PHPUnit's
  `assertInstanceOf()` ("Condition is always 'true' because '$x' is evaluated at this point"; declare the needed
  methods in the interface instead)
- `LoopToArrayMapRule`: a foreach loop directly after `$x = [];` that only assigns one value per element to `$x[]` or
  `$x[$key]` ("Loop can be converted to an 'array_map()' call")
- `RedundantUnusedParameterSuppressionRule`: `@noinspection PhpUnusedParameterInspection` on a method that overrides
  or implements a method of a parent class or an interface, or that uses all its parameters ("Redundant suppression";
  PhpStorm reports no unused parameters of such methods)
- `NarrowedPropertyAccessRule`: a property or method of the value of a property that only exists after an
  `instanceof` check of the property ("Potentially polymorphic call"; PhpStorm only narrows variables and properties
  of `$this`, assign the property to a variable first)
- `RedundantVarTagRule`: an inline `@var` tag of an assignment that specifies the type the assigned value already
  has, also by docblock ("@var tag specifies the type already inferred from source code"; not for calls with template
  types, which can depend on the Magento version, like `createBlock()` since 2.4.9)
- `TestDoubleInClosureRule`: a test double of PHPUnit created inside a closure (PhpStorm does not resolve the type of
  `createStub()` of a generated factory there and reports "Expected parameter of type 'XFactory', 'object&Stub|Stub'
  provided"; create it outside, for example in a helper method)
- `VariableOnlyUsedInClosureRule`: a variable that is assigned once and then only passed by value to one closure with
  `use` ("Variable is only used inside closure"; assign it in the closure)
- `UnusedParameterRule`: a parameter that the method does not use ("Unused parameter"; not for methods that override
  another one, methods without statements, which are hooks, and promoted constructor parameters; methods with a given
  signature, like the methods of plugins, suppress it with `@noinspection PhpUnusedParameterInspection`)
- `MissingParentConstructorCallRule`: a constructor that does not call the constructor of its parent class ("Missing
  parent constructor call"; skipping it on purpose needs `@noinspection PhpMissingParentConstructorInspection` with a
  reason)
- `RepeatedAssertionRule`: `assertTrue()`, `assertFalse()`, `assertNull()` or `assertNotNull()` of a method call like
  `$object->isX()` that was already asserted, without any use of `$object` in between ("Assertion is always 'false'
  because '$this->assertTrue($object->isX())' is already 'true' at this point"; PhpStorm does not see changes through
  other objects, assert the state through the object that changed it)
- `OverwrittenAssignmentRule`: an assignment to a variable whose value is overwritten before it is read ("Unused local
  variable. The value of the variable is overwritten immediately"): the next statement that uses the variable assigns
  it, or the first statement of a loop body that uses it assigns it and the variable is not used after the loop.
- `UnthrownReflectionExceptionRule` (with `ReflectionThrowsTagCollector` and `ReflectionThrowingCallCollector`):
  `ReflectionException` in the throws tag of a test method that calls nothing which declares it ("Exception
  'ReflectionException' is never thrown in the function"); PHPStan's own check of these tags is off for tests, since it
  narrows reflection of named classes, which PhpStorm does not
- `ReflectionInstanceReturnRule`: an instance created by `ReflectionClass::newInstance()`, `newInstanceArgs()` or
  `newInstanceWithoutConstructor()`, returned by a function with a class as native return type ("Return value is
  expected to be 'X', 'object|string' returned"; declare `object` and the class in the return tag)
- `MissingReturnTypeRule`: a method without native return type, also in interfaces and traits ("Missing function's
  return type declaration"; not for constructors, destructors, `__clone()` and methods that return a resource).

`PhpStormThrowTypeExtension` adds the checked exceptions that the PHP stubs of PhpStorm declare to PHP's functions and
methods, for example `DOMException` of `DOMDocument::saveXML()`, so that missing `@throws` tags are reported as in
PhpStorm ("PHPDoc comment doesn't contain all the necessary @throws tags"). For other code it counts only the
exceptions of the called method's own `@throws` tag (or of its parent with `@inheritDoc`), not those PHPStan inherits
from a parent method, which PhpStorm reports as "Exception is never thrown in the function" (for example
`Collection::addFieldToFilter()` for its override in `AbstractDb`).

`tools/generate-phpstorm-throws.py <phpstorm-stubs>` generates its list `src/phpstorm-throws.php` (also the functions
with results that can be false or null, and the parent classes of the classes that inherit listed methods) from a
checkout of https://github.com/JetBrains/phpstorm-stubs.

## Development

The rules run with PHPStan 1 (php-parser 4) and PHPStan 2 (php-parser 5), so they compare node types (`getType()`)
instead of using node classes that php-parser 5 deprecates. The checks of every push:

- PHPStan analyses the rules with themselves at level 9 (`phpstan.neon`), with PHPStan 1.12 and 2
- `tools/check-code-conventions.sh` of the workflows checks the rules and the shared PHP files (`bootstrap.php`,
  `versions.php`), which PHPStan does not analyse without a Magento installation: documented parameters, imports,
  readonly classes, no inline `@var` tags, no undefined variables and no calls with checked exceptions that PhpStorm
  would report
- Rector (syntax of PHP 8.2) and PHPCPD (duplicated code)

```bash
composer install
vendor/bin/phpstan analyse
```

A push to `main` with a new `version` in `composer.json` and its section in `CHANGELOG.md` releases the version when
the checks pass; the modules move to it with `phpstanPackageVersion` in `tools/sync-module-config.sh` of the workflows.

## License

MIT, see [LICENSE](LICENSE).
