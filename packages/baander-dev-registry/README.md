# baander/dev-registry

Development qualification and inventory helpers for the registry. Development only.

`src/StaticAnalysis/` holds registry static checks. The `scripts/test-registry*.php` entrypoints exercise the registry; its service remains a separate project.

Direct Baander package dependencies: `baander/configuration`, `baander/containers`, `baander/images`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
