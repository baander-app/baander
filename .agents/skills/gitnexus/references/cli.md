# Index maintenance

The generated `.gitnexus/run.cjs` selects a global GitNexus, then pnpm dlx,
bunx, or npx. This checkout currently runs cached GitNexus 1.6.12 through pnpm.
Runner fallback can install a package; confirm local availability/version rather
than interpreting a missing runner as permission to upgrade shared tools.

```bash
node .gitnexus/run.cjs --version
node .gitnexus/run.cjs status
node .gitnexus/run.cjs analyze --index-only
node .gitnexus/run.cjs list
```

For a fresh checkout use `gitnexus analyze --index-only` when available, otherwise
`npx gitnexus@1.6.12 analyze --index-only` (the version validated for these guides).
An npm 11 installation failure may require the already installed pnpm runner
or a deliberate tool installation; do not continually retry a failing installer.

Default `analyze` injects AGENTS.md/CLAUDE.md and standard skills in both retired
`.claude/skills` and `.agents/skills`. `--skip-agents-md` does not skip skills;
`--skip-skills` does not skip community generation requested by `--skills`.
Use `--index-only` to suppress all injection. Curated policies/skills are maintained
in Git separately. Never add `--skills` to the routine refresh invocation.

Add `--pdg` to an index-only refresh when taint/control/data analysis is needed;
`explain`/`pdg_query` depend on those optional layers. `--force` rebuilds the graph;
embedding generation is optional and may require runtime downloads or credentials.

`analyze --watch` is a distinct long-lived local watcher. It skips injection and
rejects `--index-only` and other one-shot flags. Scheduled remote clone/pull uses
`auto-sync`; bare `watch` is not the local watcher. After a refresh, MCP/HTTP
servers check for a replacement periodically (MCP roughly every five seconds);
verify loaded freshness instead of requiring a client restart.

`clean` unregisters/deletes an index; `clean --all` affects other repositories.
Use only for requested removal or a diagnosed corrupt index. `wiki` invokes an
LLM provider and writes documentation; `--gist` publicly publishes it. Neither
is part of normal index upkeep. See installed `wiki --help` before use.
