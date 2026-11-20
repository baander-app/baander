# baander/dev-ci

CI architecture checks, backend orchestration and artifact storage. Development only.

`src/Architecture/` owns source-policy checks; `src/Backend/` coordinates backend checks and tests; `src/Artifacts/` manages the CI package store. Entrypoints include `scripts/check-tooling-source-policy.php`, `scripts/backend-test-runner.php` and `scripts/ci-package-store.php`.

Direct Baander package dependencies: `baander/configuration`, `baander/containers`, `baander/images`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
