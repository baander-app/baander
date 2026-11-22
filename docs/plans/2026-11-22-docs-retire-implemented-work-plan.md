---
title: Retire implemented work from the roadmap and plans
date: 2026-11-22
type: docs
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: knowledge-work
---

# Retire implemented work from the roadmap and plans

## Goal Capsule

Keep [ROADMAP.md](../../ROADMAP.md) focused on remaining work, acceptance gates,
and decisions. Preserve completed work and its verification in a pinned Git
revision.

## Product Contract

- R1. Retire a plan when tracked implementation and verification evidence close
  its own scope. Keep plans with unresolved acceptance and the deferred React
  Native plans.
- R2. Keep open decisions, release gates, workflow limits, and relevant evidence
  in the roadmap. Remove completed delivery narratives and superseded gaps where
  later evidence closes them.
- R3. Preserve incoming links to roadmap sections. Update any tracked reference
  to a retired plan using maintained guidance or a pinned revision.

## Planning Contract

Follow the earlier cleanup in `deefcff20`. The roadmap records a recoverable
revision and commands for inspecting the historical roadmap and plans. Pin the
revision after the Composer checkpoint has been committed. Retire the cleanup
plan in a separate commit once the documentation checks pass.

## Implementation Units

### U1. Verify and retire completed plans

Check scope and acceptance against the recorded delivery, then remove the plans
that qualify. Repair links from surviving documents to those plans.

### U2. Trim the roadmap and preserve remaining acceptance

Reconcile delivery sections against later checks and the current code. Keep the
source-installation checkpoint, release gates, deferred scope, and any open work
in historical sections. Replace earlier historical pointers with a pinned
revision that retains those pointers and the recent delivery evidence.

## Verification Contract

- Verify retired plans and the historical roadmap are readable at the pinned
  revision. Compare retained plans against that revision.
- Check local links in the roadmap and tracked links to it, including fragments.
  Check for references to every retired plan.
- Reconcile the remaining work against the prior roadmap. Run `git diff --check`
  and the project prose review.
