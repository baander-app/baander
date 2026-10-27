# Standalone workflow tooling

The `baander/tooling` Composer package maps `Baander\Tooling` to `src/`.
Both the root manifest and `scripts/composer.json` require it through a local path
repository, so PhpStorm and runtime loading use the same namespace. The library
requires PHP 8.5 and has no external package dependencies. Generate the scripts
loader with `composer --working-dir=scripts install --no-dev` or the root command
`composer tooling:autoload`, which installs both the `scripts/` and `relay/`
Composer projects. `scripts/bootstrap.php` requires the scripts loader without
booting the application kernel. Workflow entrypoints call
`Runtime\Requirements::check()` before changing state. The baseline is PHP 8.5
with Swoole. Leaf commands that do not run processes can pass `false`; commands
needing additional extensions pass their names in the second argument.

`Process\SignalScope` keeps SIGINT/SIGTERM ownership across workflow operations;
runner waits share that ownership and retain the first interruption. Use it outside
application event loops: Swoole cannot return a foreign signal handler for
restoration. GNU `env` resets inherited signal masks before starting children.
Multiple processes can start before a wait, and each wait services all active
children. Captured output is returned in the result. With `logPath`, output streams
to a private, truncated file and the result's output strings are empty. The log
directory must be owned by the current user and must not permit other users to
write. Interactive processes inherit the caller's descriptors.

Use `stderrLogPath` when diagnostics must stay separate from structured stdout.
`appendLog` preserves earlier output when several commands share a log. A command
can set `terminationGrace` when its cancellation handler needs time to finish;
normal completion retains the short descendant-cleanup bound.

The filesystem helpers preserve private state with same-directory replacement,
file and directory synchronization, and process-owned locks. They reject symlink
paths. State directories belong to the current user; these checks do not isolate
the files from other processes running as that same user.

Tooling tests use the root Composer dependencies for PHPUnit, without booting the
application kernel. `composer test:tooling` runs the suite excluding
`registry-python-subject`; `composer test:registry-subjects` runs that group against
the unchanged registry backup service and enrollment issuer. The separate subject
gate requires Python 3, selected with `REGISTRY_SUBJECT_PYTHON` when needed. Both
gates are mandatory in CI. Static analysis uses `composer analyse:tooling`. These
test and analysis commands first install the standalone loaders.
Format workflow PHP with `composer format:tooling`; verify formatting with
`composer check:tooling-format`. The scoped PHP-CS-Fixer configuration covers this
package, migrated scripts, registry harnesses, selected runtime fixtures and the
prose audit helper. Use PHP 8.5 pipe chains for sequential transformations that
would otherwise require nested calls. Keep
control blocks expanded and split long argument lists and structured arrays
across lines.

The `tooling` target in `docker/runner/Dockerfile` provides the pinned runtime
without Python, pcntl or posix. CI uses `docker/runner/tooling-test.Dockerfile`
to run the harness suite in that runtime with the application CI image's source
and Composer dependencies. The excluded Python subjects run in their separate gate.
