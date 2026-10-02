---
name: sync-github
description: Publish Baander's committed tree as a single root commit to its GitHub mirror when the user requests a snapshot sync.
---

# Publish a GitHub snapshot

Forgejo `origin` retains development history. The GitHub mirror receives a root
commit containing the selected checkout's committed `HEAD` tree. This workflow
does not stage dirty files or move any local branch.

Use [scripts/sync_github.py](scripts/sync_github.py) from the source checkout.
It requires Python 3 and Git. Resolve the script path from this skill directory.
Inspect the actual remote before choosing a destination: this checkout uses
`baander-github`, with push URL `git@github.com:baander-app/baander.git`.

```bash
python3 /absolute/skill/path/scripts/sync_github.py \
  --remote baander-github --branch master \
  --expected-url git@github.com:baander-app/baander.git --dry-run
```

The preview reads the remote branch and reports source commit, tree, destination,
and observed SHA. It creates no Git objects or refs. Dirty work is reported as
excluded. The entire committed tree is exported, including tracked environment
files and development certificates if present. Inspect every tracked path for
the requested public export; the helper applies no automatic secret filter. A preview
does not authorize an unrelated destination or publishing additional material.

When the user has requested publication, pin both the preview's source commit
and remote SHA:

```bash
python3 /absolute/skill/path/scripts/sync_github.py \
  --remote baander-github --branch master \
  --expected-url git@github.com:baander-app/baander.git \
  --expected-source SOURCE_COMMIT --expected-sha OBSERVED_SHA --apply
```

Use `--expected-sha absent` only when the preview confirmed the target branch
does not exist. The helper refuses a destination mismatch, multiple push URLs,
or a changed source commit or remote SHA; it pushes the new root commit directly with an exact
force-with-lease. It does not retry a rejected lease. Reinspect a changed
destination and reassess the intended sync before trying again.

The helper respects configured pre-push hooks and remote branch protection.
Its own operations preserve branches, index, and worktree; operator-configured
hooks retain their usual behavior. Git commands have a 60-second timeout. A push
timeout has an uncertain remote outcome: inspect the remote before considering
another publication attempt. A failed apply may leave an unreferenced root commit
object. Automatic Git maintenance is disabled for this operation.

Run the reproducible local tests with:

```bash
python3 /absolute/skill/path/scripts/test_sync_github.py
```

Tests use temporary bare Git repositories and no live network. They exercise
publication, previews, preserved local state, destination and lease rejection,
configured hooks, and simulated timeout reporting.
