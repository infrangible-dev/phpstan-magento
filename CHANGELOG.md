# Changelog

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
