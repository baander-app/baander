# Trace a defect

Establish the observed symptom and use the repository's debugging workflow.
GitNexus supplements reproduction and source inspection; graph proximity alone
does not prove root cause.

```bash
node .gitnexus/run.cjs query "symptom or error text" --repo .
node .gitnexus/run.cjs context "suspectSymbol" --repo .
node .gitnexus/run.cjs trace "entrySymbol" "suspectSymbol" --repo .
node .gitnexus/run.cjs detect-changes --scope compare --base-ref master --repo .
```

Use MCP `query`, `context`, `trace`, or `detect_changes({scope: "compare",
base_ref: "master", repo})` when exposed. Provide `worktree` for MCP diff analysis
in a linked checkout the server was not launched from. For CLI, execute in that
checkout and use `--repo .`. Confirm the actual base if the task names another.

Follow incoming/outgoing refs, then inspect source, exceptions, async boundaries,
and runtime configuration. For custom Cypher, read the bound repo's schema first
and use `cypher` with explicit repo identity. `trace` returns shortest CALLS/
HAS_METHOD paths and status; a missing path may be dynamic dispatch or a traversal
limit, rather than unreachable code. Check truncation and disambiguation.

Report the reproduced evidence separately from graph-derived hypotheses, with
repository and index freshness. A stale index can describe code from before the
regression; refresh index-only when the graph is needed to support the diagnosis.
