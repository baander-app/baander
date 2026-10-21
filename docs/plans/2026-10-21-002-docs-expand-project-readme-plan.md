---
title: Expand the project README - Plan
type: docs
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: legacy-requirements
origin: docs/plans/2026-10-21-001-docs-user-facing-readme-plan.md
execution: code
---

# Expand the project README - Plan

## Goal Capsule

Objective: A prospective user understands Bånder's purpose, available capabilities, everyday use and current setup requirements from the README.

Means: Expand the introduction into a substantial, readable project overview, with links to the existing operator and developer guides.

Authority: The user's correction, “i expected a much longer readme that explains more about the project”, supersedes the previous plan's five-short-sections constraint. Its accuracy, audience and operational limitations still apply.

Stop conditions: Keep this follow-up to documentation. The lead verifies the claims and links, records the checkpoint in `ROADMAP.md`, and commits locally. Push remains outside the authorized scope.

## Product Contract

### Summary

The previous rewrite is too short to explain the project. Expand the README to roughly 700–1,000 words, using that range as editorial guidance rather than a quota. Explain the project through concrete capabilities and a reader's path from importing a collection to browsing and playback.

### Requirements

**Project explanation**

- R1. Explain Bånder's purpose as a self-hosted server for organizing and playing a personal media collection.
- R2. Describe supported capabilities from current source and canonical documentation: libraries and scanning, browsing and search, metadata enrichment, playlists and playback. Qualify supported media types, formats and integration requirements when relevant; distinguish implemented behavior from incomplete interfaces.
- R3. Give the reader a practical account of using an instance, including adding media, scanning a library and accessing it through available clients or interfaces. Avoid promises unsupported by source.
- R4. Use enough connected prose and useful sections to explain the project substantially; replace the inherited five-short-sections constraint.

**Setup and navigation**

- R5. Explain the current source-build setup and separate worker deployment requirement, linking the existing installation and worker guides without copying their command sequences.
- R6. Retain accurate links for operators, development, contribution and licensing, including package-specific license exceptions.
- R7. Keep architecture tables, internal directory inventories and duplicated CLI reference material out of the README.

**Verification and delivery**

- R8. Verify changed factual claims against source or owning documentation, check local links, run prose-fix preservation review and whitespace checks, and obtain independent review before delivery.
- R9. Record the expanded README and actual verification checkpoint in `ROADMAP.md`; make a local commit without pushing.

### Scope Boundaries

Only the README, this follow-up plan and the roadmap need edits. Correcting an inaccurate linked guide is permitted only if essential to the README's changed claims and supported by evidence. Add no application features, installation commands or deployment changes. The previous disposable bootstrap encountered an Xdebug image-build blocker; this prose expansion does not change the runtime path or require repeating that blocked walkthrough. Do not claim successful runtime verification.

## Planning Contract

### Key Technical Decisions

- KTD1. Keep detailed installation, worker operations and CLI material in their existing guides; use the README to explain the project and point readers to those references.
- KTD2. Preserve the user's request for a substantially longer explanation. session-settled: user-directed; rejected alternative: retaining the short five-section introduction.
- KTD3. Verify capability descriptions in source before adding them. Existing documentation is useful evidence but cannot establish behavior that contradicts the implementation.

### Sequencing and Assumptions

Review this follow-up plan before README edits. The lead writes and integrates the expansion while independent review checks accuracy, audience and navigation. No new shared vocabulary is introduced, so `CONCEPTS.md` needs no change. The user has authorized implementation; there is no unresolved product decision.

## Implementation Units

### U1. Expand and verify the README

Files: `README.md`.

Approach: Preserve a temporary baseline for the prose audit; verify capabilities and limitations through bounded source reads; write a substantially expanded overview covering purpose, collection management, discovery, listening or viewing, operation and development. Use factual reader-facing language and link the owning guides. Run prose-fix and independent review, applying justified corrections.

Verification: Check each new capability and limitation against its source, resolve local Markdown links and inspect the preservation audit. Confirm that setup still clearly requires a source build and separately deployed workers. Application tests are unnecessary for a documentation-only change.

### U2. Record and commit the result

Files: `ROADMAP.md` and corrections to `README.md` supported by U1 review.

Approach: Record delivered scope, documentation review and link-check evidence, applicable static checks, and the unchanged runtime verification limit. Run the documentation phase simplification and final review, then commit locally on the plan-named branch.

Verification: Run `git diff --check`, inspect the final diff and confirm that the commit contains only authorized documentation. State application-suite counts as not run for this follow-up; do not represent inherited checks as a new runtime pass.

## Verification Contract

All newly expanded factual claims have source evidence; local links resolve; prose review preserves operational and licensing qualifications; independent review has no unresolved material accuracy or scope finding. `git diff --check` passes. The roadmap distinguishes documentation verification from the previously blocked bootstrap. No new application suite or runtime walkthrough is required because neither application behavior nor installation steps change.

## Definition of Done

A reader can understand what Bånder offers, how a collection becomes usable, and what running or developing an instance entails without reading internal architecture material first. The substantially expanded README retains setup and licensing limitations, passes documentation checks and independent review, and is recorded in the roadmap and a local commit.
