# baander/process

Process execution and command-line runtime primitives for workflows. Available to operational workflows.

`src/Execution/` owns command execution, completion and signal handling; `src/Arguments/` handles command arguments and `src/Runtime/` checks runtime requirements.

Direct Baander package dependencies: `baander/filesystem`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
