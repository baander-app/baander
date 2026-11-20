# baander/dev-website

Documentation website rendering, preview and publication. Development only.

`WebsiteBuilder`, `Command` and `Preview` are entrypoints. `src/Content/`, `src/Markdown/`, `src/Assets/`, `src/Metadata/` and `src/Evidence/` group the rendering work. Use `scripts/build-website.php` to build the site.

Direct Baander package dependencies: `baander/configuration`, `baander/filesystem`, `baander/process`. See [composer.json](composer.json) for all requirements.

Package-owned tests live in `tests/` and run through the shared root tooling runner. See the [workflow reference](../../dev-docs/workflow-packages.md#checks) for commands and the separate registry subject gate.
