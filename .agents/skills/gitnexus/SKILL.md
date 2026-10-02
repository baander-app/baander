---
name: gitnexus
description: Use Baander's GitNexus index for code exploration, debugging, edit impact, refactoring, graph queries, and index maintenance.
---

# GitNexus in Baander

These workflows consolidate the repository's generated GitNexus guides, checked
against CLI 1.6.12. Treat the graph as evidence to confirm against source, not a
complete model of reflection, dynamic dispatch, or runtime wiring.

Bind the repository and checkout before querying or editing. With MCP, call
`list_repos` and follow `pagination.nextOffset` until the intended repository is
found; pass an explicit `repo` when multiple repositories are indexed. With CLI,
run from the intended checkout and pass `--repo .` for graph/diff commands.
Check `node .gitnexus/run.cjs status`: matching HEAD alone does not establish
freshness because uncommitted source can differ. State repository, worktree,
and index freshness with substantive findings.

Refresh with `node .gitnexus/run.cjs analyze --index-only`. If the generated
runner is absent, use an available GitNexus executable or `npx gitnexus@1.6.12 analyze
--index-only`. Default analysis recreates retired agent files and overwrites
standard skills; follow [CLI guidance](references/cli.md) before maintaining
the index. Do not assume MCP tools are configured; use CLI alternatives and
check command help for the installed version.

Read only the reference needed:

| Task | Reference |
| --- | --- |
| Understand code or execution flows | [Exploration](references/exploring.md) |
| Trace an error or regression | [Debugging](references/debugging.md) |
| Assess an edit or review changed scope | [Impact](references/impact-analysis.md) |
| Rename, extract, move, or split code | [Refactoring](references/refactoring.md) |
| Index lifecycle and runner setup | [CLI](references/cli.md) |
| Tool/resource/schema lookup | [Tools](references/tools.md) |

Before editing an existing function, class, or method, run upstream impact as
required by AGENTS.md; report callers, affected processes, and risk. Warn before
HIGH/CRITICAL changes. Resolve UNKNOWN risk with source and text searches rather
than treating zero callers as safe. Partial/truncated output is incomplete;
rerun with a sufficient bound or document unresolved coverage. Before a commit,
use `detect_changes` and verify the checkout and expected scope against Git diff.
An empty result from the wrong checkout has no degradation flag.
