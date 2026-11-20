# baander/dev-environment

The local development controller, previews and retained releases. Development only.

Use `scripts/dev-environment.php` or the documented Make targets. `src/Controller/` publishes and supervises controllers; `src/Release/`, `src/State/`, `src/Runtime/`, `src/Host/`, `src/Configuration/` and `src/Worker/` hold their respective responsibilities.

Direct Baander package dependencies: `baander/core`, `baander/configuration`, `baander/containers`, `baander/deployment`, `baander/images`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
