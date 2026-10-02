# Explore a flow

Bind the repository and inspect freshness as described in SKILL.md. Then search
for the concept, inspect returned execution flows, and inspect relevant symbols.
Read the underlying files to distinguish discovered edges from actual behavior.

```bash
node .gitnexus/run.cjs query "token revocation" --repo .
node .gitnexus/run.cjs context "findByTokenId" --repo . --file src/Auth/Infrastructure/Cache/CachedAccessTokenRepository.php
node .gitnexus/run.cjs trace "save" "setRevoked" --repo . --from-file src/Auth/Infrastructure/Cache/CachedAccessTokenRepository.php
```

MCP equivalents are `query({search_query, repo})`, `context({name, repo})`, and
`trace({from, to, repo})`. Use UID or file hints for ambiguous names. Returned
processes are navigation aids, not proof that runtime dispatch follows that path.

If resources are configured, read `gitnexus://repo/{name}/context` for overview,
`clusters`/`cluster/{name}` for areas, and `processes`/`process/{name}` for flows.
CLI query/context results provide navigation when resources are unavailable.
Report repository/worktree/index identity with the explanation and cite source
for behavior the graph cannot establish.
