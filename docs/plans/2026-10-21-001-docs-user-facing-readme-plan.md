---
title: Make the README a user-facing introduction - Plan
type: docs
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: user-supplied-plan
execution: code
---

# Make the README a user-facing introduction - Plan

## Goal Capsule

Objective: Readers can understand what Bånder does and follow an accurate path to their first working instance.

Means: Rewrite the introduction and source-installation path, and relocate messaging detail to its existing references (KTD1).

Authority: The user-supplied plan defines scope; current implementation and canonical documentation establish commands and supported behavior. Report implementation contradictions rather than inventing deployment guarantees.

Stop conditions: An application blocker encountered during the disposable bootstrap is reported with evidence. Do not expand into deployment redesign. The lead integrates, verifies, records the checkpoint in `ROADMAP.md`, and commits locally; merge and push require separate requests.

## Product Contract

### Summary

The README currently mixes a product introduction with internal architecture and messaging operations. Replace it with a short introduction for someone deciding whether to use Bånder. Correct the linked installation path and preserve operational knowledge in the existing guides.

### Requirements

#### README and navigation

- R1. Use five short sections: what Bånder is, what users can do, getting started, developer resources, and license.
- R2. Describe Bånder as a self-hosted server for organizing and playing a personal media collection, with concise verified descriptions of browsing, metadata enrichment, search, playlists, and playback.
- R3. Explain that setup currently builds from source and requires separately deployed background workers; prominently link the full installation guide.
- R4. Link the developer guide, development environment, and contribution instructions, and retain the license link and package-specific licensing exceptions.
- R5. Remove the bounded-context table, internal directory layout, technology inventory, and messaging section from the README; remove the documentation index's unsupported “under 10 minutes” claim.

#### Ordered source installation

- R6. Explain prerequisites and the development-oriented deployment, including the worker controller's host requirements.
- R7. Prepare configuration, hostname/TLS, the external Docker network, and shared media storage before startup, using the existing worker storage contract instead of personal paths.
- R8. Configure Redis credentials before starting Redis, explaining environment-file precedence so the development secret cannot silently replace the chosen setting.
- R9. Build images and start dependencies, then install Composer dependencies through a one-off app container before starting web, preserving runtime autoloader generation.
- R10. Initialize only the main database with the existing guarded migration command, linking existing upgrade/reset guidance rather than duplicating migration internals.
- R11. Generate initial OAuth keys, configure the first-party client, and create an administrator with a chosen password.
- R12. Install and build the frontend using the image's Node/Corepack tooling and repository-pinned Yarn version.
- R13. Start web and follow the existing version-4 worker deployment procedure, which remains authoritative for the manifest.
- R14. Create a library with its required media-type argument, scan it, and open an explicit HTTPS address using `WEB_PORT_TLS`.

#### Messaging knowledge preservation

- R15. Extend the existing CQRS and messaging guide with the JSON message contract, retry/idempotency semantics, transactional outbox, and replay behavior; replace obsolete Supervisor/manual-consumer deployment advice with current worker-guide links.
- R16. Preserve missing heartbeat cadence, last-dequeued-message metric semantics, leased handoff retries, and dead-letter behavior in monitoring and outbox command references.
- R17. Preserve queue/retry-data precautions and unsupported legacy-payload warnings in the upgrade guide.
- R18. Retain the testing guide's runner and runtime-drill coverage while removing README duplicates.
- R19. Preserve limitations concerning duplicate external delivery and the distinction between verified producer transactions and a complete producer audit.

#### Boundaries and verification

- R20. Check each changed command against implementation and each changed link against its destination.
- R21. Verify the documented bootstrap in a disposable checkout with isolated services, storage, credentials, and ports, checking frontend loading, administrator login, worker startup, and a small sample-library scan.
- R22. Do not reset or reuse local application databases, Redis, or media storage; report any application blocker uncovered by the walkthrough.
- R23. Run prose-fix preservation checks, whitespace checks, and independent documentation review; no new application test suite is required.
- R24. Record delivered scope and the verification checkpoint in `ROADMAP.md` and commit locally, leaving merge and push for separate requests.

### Scope Boundaries

This work changes documentation only. It adds no installer, changes no deployment code, documents no destructive shortcuts, and makes no unsupported installation-time promise or claim that Compose alone starts a complete instance.

### Sources

The user-supplied “Make the README a user-facing introduction” plan is the product source. Existing guides and source files are implementation evidence, not permission to broaden scope.

## Planning Contract

### Key Technical Decisions

- KTD1. Reuse existing operator and developer guides as the owning references; this preserves audience boundaries and avoids an overlapping messaging guide (R1–R5, R15–R19).
- KTD2. Derive installation commands from current Compose, environment loading, console commands, image tooling, and version-4 worker documentation; runtime discovery belongs to the isolated walkthrough (R6–R14, R20–R22).
- KTD3. Capture each edited document's working-tree contents outside the repository before rewriting, then review preservation-audit deltas against the intended relocation and corrections (R23).

### Sequencing and Dependencies

Review this plan before implementation. The README and installation work can proceed alongside messaging relocation with separate file ownership. Integration follows both streams; the disposable walkthrough tests the resulting instructions. Record and commit the verified result after independent review and corrections.

### Assumptions and Open Questions

The supplied plan settles the intended scope. There is no launch-blocking product question. Whether the current application completes the bootstrap is an execution-time unknown to resolve under R21–R22, not a reason to change deployment code.

## Implementation Units

### U1. Rewrite the introduction and installation path

Requirements: R1–R14, R20. Decisions: KTD1–KTD3.

Files: `README.md`, `docs-book/README.md`, `docs-book/part-1-operator-guide/getting-started.md`.

Approach: Verify capabilities and commands in source, then write the five-section README and one ordered installation path. Resolve linked worker, configuration, security, and upgrade references from current documentation rather than duplicating them.

Verification: Each claim and command has source evidence; links resolve; configuration and tool-version ordering is explicit. Preserve license exceptions and inspect prose-audit deltas. Test expectation: no new application tests because this unit changes documentation only; U3 exercises the documented runtime path.

### U2. Relocate messaging operations and developer detail

Requirements: R15–R20. Decisions: KTD1, KTD3.

Files: `docs-book/part-2-developer-guide/cqrs-and-messaging.md`, `docs-book/part-1-operator-guide/monitoring.md`, `docs-book/part-1-operator-guide/commands/app-outbox-consume.md`, `docs-book/part-1-operator-guide/upgrading.md`; inspect `docs-book/part-2-developer-guide/testing.md` and update only if preservation requires it.

Approach: Map every removed README messaging fact to an existing destination or existing equivalent coverage. Verify semantics against implementation, retain qualifications, and replace outdated deployment advice with links.

Verification: Inspect the relocation map and audit deltas for lost conditions, timing values, metric meanings, retry behavior, or caveats. Test expectation: no new application tests because this unit changes reference prose only.

### U3. Verify the isolated bootstrap and deliver

Requirements: R20–R24. Depends on U1 and U2.

Files: `ROADMAP.md` and corrections to U1/U2 documents supported by verification evidence.

Approach: Follow the proposed guide in a disposable checkout after establishing isolation. Use a disposable media sample and capture bounded logs outside the tracked tree. Report failures at the first application blocker while recording which later checks remain unverified.

Verification scenarios: A new reader can follow the command order; the frontend loads at the documented HTTPS address; the chosen administrator can log in; workers start through the authoritative deployment procedure; a library created with a media type scans the sample. Confirm protected local resources were neither reused nor reset. Independent review checks the final document diff and all justified findings are applied before the local commit.

## Verification Contract

- Use implementation evidence for commands and direct destination checks for changed links (R20).
- Perform the isolated runtime walkthrough and retain pass/fail/blocker evidence for each R21 scenario; a blocker is not a passed walkthrough (R21–R22).
- Run `.agents/skills/prose-fix/scripts/audit_rewrite.py` with each captured baseline and edited document, inspect intentional changes, and run `git diff --check` (R23).
- Run independent documentation review and apply justified corrections; use the repository's simplification and final review workflow at phase boundaries (R23).
- Record actual checks, counts where applicable, and unresolved runtime limitations in `ROADMAP.md`; do not claim application suites were run when they were not (R24).

## Definition of Done

The README, installation path, and relocated reference material meet their owning requirements. Every changed command and link is checked. Preservation and whitespace checks pass, independent review is resolved, and the isolated bootstrap has either verified all requested scenarios or produced a documented application blocker with the remaining scenarios explicitly unverified. The roadmap records delivered scope and evidence, and the result is committed locally without merge or push.
