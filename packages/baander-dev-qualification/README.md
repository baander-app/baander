# baander/dev-qualification

Disposable fixtures and acceptance workloads for development and runtime qualification. Development only.

`src/Acceptance/` exercises the local environment; `src/Fixtures/` groups HTTP, messaging, startup, web, worker and other qualification workloads. Entrypoints include `scripts/qualify-deployment.php` and the `scripts/test-*-container.php` workflows.

Direct Baander package dependencies: `baander/configuration`, `baander/containers`, `baander/deployment`, `baander/dev-environment`, `baander/images`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
