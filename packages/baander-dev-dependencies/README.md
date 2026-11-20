# baander/dev-dependencies

PHP and JavaScript dependency maintenance. Development only.

`src/Php/` and `src/JavaScript/` implement their respective checks. The PHP policy is in `resources/php-dependencies.json`; run the `scripts/check-php-dependencies.php` or `scripts/check-js-dependencies.php` entrypoint.

Direct Baander package dependencies: `baander/configuration`, `baander/containers`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
