# baander/filesystem

Filesystem operations used by workflow packages, including private state, atomic replacement and locking. Available to operational workflows.

Start in `src/` for the filesystem helpers. They reject symlink paths where private state is managed.

Direct Baander package dependencies: none beyond PHP. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
