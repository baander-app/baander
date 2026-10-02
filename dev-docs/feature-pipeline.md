# Feature Delivery

Deliver a requested feature through implementation, review, and the repository's
required checks. The former detached-pi pipeline is retired. This guide describes
work using the available agent tools; it is not an automatic issue, push, or merge
workflow.

## Scope and preparation

Read [AGENTS.md](../AGENTS.md), the applicable [rules](../.agents/rules/), and the
user's feature requirements. Inspect the working tree and preserve existing work.
Use an isolated worktree or checkout when parallel tasks need separate branches.
A dirty tree or a branch other than the default branch is not by itself a reason
to discard work or stop an otherwise authorized local task.

Use GitNexus to understand unfamiliar flows and analyze impact before changing
symbols. Keep a plan proportional to the work, with concrete implementation units,
relevant checks, and any unresolved product decisions. A plan is not authorization
to create remote issues, send comments, publish, or merge.

## Implementation and delegation

For substantial independent tasks, use the available collaboration tools with
bounded objectives, deliverables, and non-overlapping file ownership. Start with
at most three workers. The lead continues independent work, integrates changes,
and owns final verification. Use focused follow-ups rather than restarting a
worker with the entire conversation.

Use existing repository skills when their scope fits. Tests should establish the
requested behavior or regression, not mirror implementation. For an unexpected
failure, trace the root cause before applying a fix. Reuse the same kernel or
service when retry behavior is part of the contract. Keep database and transport
checks on disposable services.

## Verification and review

Run checks appropriate to the changed behavior. The [testing guide](../docs-book/part-2-developer-guide/testing.md)
describes strict unit, messaging, and production runtime runners. Backend checks
also include PHPStan and Deptrac where applicable; web checks use the package's
Yarn scripts. Report limitations and unresolved failures explicitly.

Delegate a focused independent review when the change warrants it. Correct
supported findings, then rerun the affected checks. A review or CI failure remains
an unresolved failure until repaired or explicitly accepted within the user's
scope; this guide provides no CI-bypass flag. Use GitNexus change detection before
an authorized commit.

## Forgejo and remote actions

When the user requests Forgejo work, use the available Forgejo integration or a
configured CLI within that scope. Discover the repository and available connection
instead of hardcoding a host, reading a shell startup file for credentials, or
assuming access to another remote.

Create a draft PR only after a concrete implementation diff exists. Describe the
resulting behavior and validation so it is reviewable. Check review and CI results
for the current implementation commit, not merely the latest run on the branch.
Pushing a branch, posting a comment, merging, deleting a remote branch, or closing
an issue must fit the user's authorization. A failed server-side merge is a
failure to investigate, not permission to bypass it with a local merge and push.

There is no automatic squash snapshot or force-push to another remote in this
workflow. Complete authorized preparation and checks before any required final
approval; ask only when the remaining action actually requires it.

## Handoff

Report the implemented behavior, relevant files, checks and results, and remaining
issues. For work spanning sessions, preserve a concise task summary and artifact
paths using the available tools. Historical pi session directories or pipeline
state files are not a supported resume protocol for the current agent tools.
