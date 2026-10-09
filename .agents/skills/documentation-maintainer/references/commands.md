# CLI reference maintenance

Maintain requested command pages under
`docs-book/part-1-operator-guide/commands/` and their README index. For a full
update, discover app-defined `AsCommand` declarations recursively under `src/`.
An import of `AsCommand` alone does not define a command. Class names ending in
Command also include Messenger messages, so do not document them as console tools.

Read each affected command's complete source and the invoked service when its
behavior matters. Establish names, aliases, arguments, options, defaults,
validation, confirmations, stdin behavior, exit codes, and side effects. Most
current commands use `configure()` and `execute()`; also handle inherited
definitions or invokable commands when present. `app:cli:manifest` exposes runtime
definitions, but obtaining them requires a configured kernel: do not contact
production or boot unavailable services simply to regenerate docs. Compare an
available manifest or safe `--help` output with source evidence.

Use the existing page structure or [the new-page template](../assets/command.md).
Pages use command names with colons replaced by hyphens. Keep useful operator
prose; update facts and examples that changed rather than rewriting every page.
Show the project's `make exec cmd="php bin/console ..."` form. Use test domains
from AGENTS.md in fixtures/examples and never present a destructive operation as
an unqualified quick start. Explain confirmation, destructive effects, prerequisites,
and recovery only where the actual command requires them.

Update the index's links and descriptions to match documented commands. Preserve
existing intentional categories; sort entries within their established grouping.
Omit Arguments/Options sections when absent. Exit-code meanings must reflect the
implementation; useful details or tips are optional rather than filler.

`check` mode reports missing pages, stale signatures/defaults/behavior, broken
links, and stale index entries without writing. Compare these facts, not exact
LLM-generated prose, so a wording change is not falsely reported as command drift.
`tests/Unit/Docs/CommandDocsCoverageTest.php` fails when a command under `src/` has
no page or no index row, when the index names a command that no longer exists, and
when a page belongs to no command or linked family page. Delete or rename a page in
the same change that removes or renames its command. Do not execute the commands'
side effects as a documentation check.

Admin commands follow the [admin and console rules](../../../rules/admin-cli-parity.md).
Document what they share: exit code 1 for an unknown target, a conflict or a declined
confirmation, exit 2 for rejected input or a missing `--force` without a terminal;
`--force`, which skips the confirmation; and `--json`, which prints the API response's
`data` payload, or nothing when the route answers 204. Name the admin panel action or
API route the command mirrors.
