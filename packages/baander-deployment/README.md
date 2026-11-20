# baander/deployment

Operational deployment presets and worker lifecycle. Available to operational workflows.

`Preset` is used by `scripts/deploy-preset.php`. `src/Presets/` contains preset configuration and state; `src/Workers/` handles worker lifecycle, with profiles, database and observability in their named directories.

`Profiles\DeploymentField` owns deployment profile field names. Its `path()`
method creates a core configuration path for a field, optionally below a supplied
parent path. `ImageProfile` and `Database\Pooler` use these paths for resolved
capacity settings. The deployment context retains its own validation and defaults.

Direct Baander package dependencies: `baander/core`, `baander/configuration`, `baander/containers`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
