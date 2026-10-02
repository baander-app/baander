# Refactor with bound identity

Map upstream impact before editing existing symbols, inspect context/callers,
then plan interface, implementation, caller, and relevant test updates. Use CLI
query/context/impact from the intended checkout as described in the other
references; MCP is optional for those reads.

GitNexus `rename` is an MCP operation in CLI 1.6.12, not a CLI subcommand:

```text
rename({symbol_name: "oldName", new_name: "newName", repo: "baander", dry_run: true})
```

Review every returned `file_path` for bound repository/worktree identity and
inspect lower-confidence text-search edits as well as graph edits. Apply with
`dry_run: false` only after reviewing that preview. AGENTS.md requires symbol
renames through `rename`, never broad find-and-replace. If the tool is unavailable,
report that dependency rather than inventing a CLI rename or applying an unsafe
replacement. Continue independent refactor analysis that does not require rename.

For extraction, splitting, or moving code, define the new interface from actual
callers and preserve runtime service wiring. Inspect references before changing
imports and retain public API compatibility or deliberate migration behavior.

Verify with `node .gitnexus/run.cjs detect-changes --scope all --repo .` or bound
MCP detect_changes, then run checks appropriate to affected behavior. Partial,
truncated, stale, or wrong-checkout output does not verify scope. Refresh with
`analyze --index-only` when new symbols require a graph rebuild.
