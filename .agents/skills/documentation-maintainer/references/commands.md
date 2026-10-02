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
Report pages for removed commands and retain them unless deletion is authorized;
some may intentionally document upgrade or historical behavior. Do not execute
the commands' side effects as a documentation check.
