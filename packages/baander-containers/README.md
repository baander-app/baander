# baander/containers

Docker transport and ownership of disposable resources. Available to operational workflows.

`src/Docker/` talks to the selected daemon; `src/Resources/` records resource and image ownership for cleanup. Build orchestration belongs to `baander/images`.

Direct Baander package dependencies: `baander/configuration`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
