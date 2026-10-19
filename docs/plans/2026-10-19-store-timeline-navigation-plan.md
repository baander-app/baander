---
title: Store timeline navigation and captured state inspection
type: feat
date: 2026-10-19
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Store timeline navigation and captured state inspection

## Goal Capsule

**Objective:** Developers can step through recorded store events and inspect the state captured at a chosen update while the application continues running.

**Means:** A stable event cursor and an isolated view of the selected event's captures (KTD1–KTD3).

**Authority:** The Developer tooling section of `ROADMAP.md` supplies the outcome; this plan defines the bounded delivery. The lead integrates, verifies and commits locally. Stop for a contradiction in recorded capture semantics; no push is authorized.

## Product Contract

### Summary and problem frame

`StoreTimeline` already filters, expands, exports and clears events. It lacks a selected event and sequential navigation. `StoreInspector` shows the latest store captures, so it cannot explain the state at a past update.

### Requirements

R1. Developers can select any retained matching event and move to the previous or next event in the filtered order, with clear selection and disabled boundary controls.

R2. Selecting an update shows its store name, event ID and its own captured before and after values in a separate, read-only inspection region. Selecting an action or completion states that it has no state capture.

R3. Inspection exposes each capture's completeness and preserves existing redaction and truncation markers. It never fills missing state from another event or from the latest store captures.

R4. Selection stays on the same event ID when new events arrive. Clear, eviction or a filter change that hides the selected event removes the selection; navigating never mutates a live store, invokes actions or triggers effects.

### Scope boundaries

Deliver navigation and isolated inspection of captured update state. Full multi-store reconstruction, action replay, trace import, editing captured values and persistence of selection are deferred. No cross-event reconstruction is added: schema v1 has no store registration epoch, and its latest `stores` map is not a historical baseline. This limitation must remain explicit in the roadmap record.

## Planning Contract

KTD1. Keep the cursor as component-local event ID state in `StoreTimeline`. Derive its effective selection from the current filtered retained events; never retain an evicted event object or silently select a replacement. Start unselected, with a prompt to choose an event.

KTD2. Navigate in the existing filtered recorder order. A native button per event selects it without changing the existing expandable event details; previous and next controls operate only when an event is selected. Use accessible labels and a visible selected state consistent with `ui/DESIGN.md`.

KTD3. Read only the selected event's frozen `before` and `after` captures. Render values and completeness independently, including unavailable captures. Keep this diagnostic view separate from `StoreInspector`; no recorder/schema changes, dependency additions or replay helper abstraction are needed.

### Assumptions

This roadmap increment adds navigation and inspection of the selected event’s captures. It advances isolated reconstruction only for directly captured update states; it does not complete general historical reconstruction. Existing capture limits and store redaction remain authoritative.

## Implementation Units

### U1. Navigate and inspect retained events

**Files:** `ui/web/src/shared/components/mediator-dev-panel/StoreTimeline.tsx`; `ui/web/src/shared/components/mediator-dev-panel/__tests__/StoreTimeline.test.tsx`.

**Approach:** Implement R1–R4 using KTD1–KTD3. Reuse the existing external-store subscription, event details and UI button component. Derive selection from the current recorder events and filter so stale captures do not remain visible.

**Test scenarios:** Select an update and move across update, action and completion events; verify values, unavailable state and boundary controls. Append another live update and verify the selected capture and ID remain pinned while the live store advances. Hide selection with a filter, clear it and evict it using a small recorder limit; verify no stale state or implicit replacement. Render incomplete and redacted captures and confirm their labels and markers survive. Assert navigation changes neither live store state nor recorder events.

### U2. Verify the browser flow and record delivered scope

**Depends on:** U1.

**Files:** `ui/web/tests/e2e/store-debug/debug.spec.ts`; `ui/web/tests/e2e/store-debug/fixture.tsx` only if an additional disposable fixture control is needed; `ROADMAP.md`.

**Approach:** Extend the existing isolated debugger browser case to select and step through recorded events, inspect an earlier update after a later live change, and confirm the current application selection remains unchanged by navigation. Record the exact delivered behavior and its verification checkpoint in the roadmap. Keep general reconstruction and live replay visibly open. Reconcile the adjacent release prerequisite against the existing production stage in `Dockerfile`, `scripts/test-worker-production-container.sh` and `.forgejo/workflows/ci.yaml`: dependency-copy ordering and production defaults already have implementation and CI checks; application backup restoration remains unverified. This is a documentation correction, not new release validation.

**Test scenarios:** Exercise previous/next and the selected capture in Chromium through the existing disposable `debug.baander.app` HTTPS fixture; verify clear removes inspection. Preserve existing disabled tracing, export, inspector and reload coverage.

## Verification Contract

Run the focused `StoreTimeline` component suite and the existing recorder suite at `ui/web/src/shared/stores/__tests__/debug.test.ts`, then the `test:store-debug` browser script defined in `ui/web/package.json`. Run the frontend typecheck, lint and build gates using the repository's supported environment. Keep large logs outside the tracked tree; record suite counts, static-gate results and environmental limits in `ROADMAP.md`.

## Definition of Done

R1–R4 have focused component coverage and a passing disposable browser flow. The selected historical capture remains independent of subsequent live updates and navigation produces no new recorder events. Frontend gates pass, review findings are resolved, and `ROADMAP.md` records both the delivered increment and the remaining reconstruction limitation.
