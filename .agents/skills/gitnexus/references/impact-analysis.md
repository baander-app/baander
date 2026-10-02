# Assess an edit

Run upstream impact for the exact existing symbol in the intended checkout:

```bash
node .gitnexus/run.cjs impact "symbolName" --direction upstream --repo .
node .gitnexus/run.cjs detect-changes --scope all --repo .
node .gitnexus/run.cjs detect-changes --scope compare --base-ref master --repo .
```

MCP equivalents are `impact({target, direction: "upstream", repo})` and
`detect_changes({scope: "all", repo})`. Pass MCP `worktree` for the linked
checkout if needed. Confirm target paths for duplicate symbol names and inspect
affected processes through results/resources, then read callers in source.

Depth 1 denotes direct callers/importers; depth 2 and 3 denote transitive
dependents. Tool wording such as WILL BREAK is a dependency warning, not a
guarantee that every caller breaks under the proposed change. Explain the actual
interface/behavior change, relevant callers, execution flows, and tool risk.

Honor the returned risk: warn before HIGH/CRITICAL changes. Zero callers can mean
unresolved dispatch, not an unused symbol; treat UNKNOWN as unresolved until
source/text searches establish coverage. Do not replace tool risk with a homemade
caller-count threshold. `riskSharedAxes` permits limited within-mode comparison,
not waiving the risk gate; File/symbol and local/group metrics differ.

`partial` means a graph query failed; `truncated` means bounded output omitted
results. Rerun or resolve coverage before claiming verification. A wrong-worktree
empty diff can have neither flag: compare Git's changed paths to the analyzed
checkout. Before committing, ensure detect_changes covers staged and unstaged
edits (`all`) and reconcile its expected scope with the source diff.
