# Tool and resource reference

Inspect installed CLI `--help` and exposed MCP schemas before using version-
specific options. CLI 1.6.12 provides `query`, `context`, `impact`, `trace`,
`cypher`, `detect-changes` (alias `detect_changes`), `check`, and index commands.
Graph commands accept `--repo`; prefer the intended checkout path.

MCP adds surfaces including `rename`, `explain`, `pdg_query`, `route_map`,
`shape_check`, `api_impact`, `tool_map`, `group_list`, and `group_sync` when
configured. There is no assumed CLI equivalent for every MCP tool; use available
source inspection rather than fabricate a command. Bind repo on each MCP call
when more than one index exists. Page `list_repos` using its returned pagination.

Resources, if configured:

| Resource suffix under `gitnexus://repo/{name}/` | Purpose |
| --- | --- |
| `context` | Overview and freshness |
| `clusters`, `cluster/{name}` | Functional areas and members |
| `processes`, `process/{name}` | Execution flows |
| `schema` | Authoritative indexed graph schema |

Read that schema before Cypher. Common relationship queries use CodeRelation
and its type, for example:

```bash
node .gitnexus/run.cjs cypher 'MATCH (caller)-[:CodeRelation {type: "CALLS"}]->(f:Function {name: "targetName"}) RETURN caller.name, caller.filePath' --repo .
```

When schema resources are unavailable, inspect the installed analyzer schema or
supported graph metadata before constructing queries. Optional PDG indexing
adds BasicBlock/control/data/taint layers. `explain` findings can expose source-
sink chains but absence of findings never proves safety: callback, property,
implicit, and cross-function flows have limited coverage. `pdg_query` is anchored
by file/symbol; controls and flows represent intra-procedural dependence.

Group tools target cross-repository contracts. Keep group identity separate from
single-repo identity; trace can cross a bounded contract link and metrics are not
directly comparable across modes. Updating a group registry is a mutation, not a
prerequisite for ordinary single-repository queries.
