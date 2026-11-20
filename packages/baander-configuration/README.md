# baander/configuration

Shared workflow settings, image roles and the application layout. Available to operational workflows.

`ToolingConfiguration` resolves settings. `src/Sources/` reads configuration, `src/Images/` selects image roles and `src/Execution/` holds Docker settings and workflow budgets. Defaults and layout live in `resources/`.

Direct Baander package dependencies: `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
