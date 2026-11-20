# baander/images

Application image builds and packaged dependency inventory. Available to operational workflows.

`src/Build/` handles build requests and `src/Inventory/` handles inventory and its worker. Use `scripts/build-application.php` to build application targets.

Direct Baander package dependencies: `baander/configuration`, `baander/containers`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
