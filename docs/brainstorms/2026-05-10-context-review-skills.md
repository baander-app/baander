---
date: 2026-05-10
topic: context-review-skills
---

# Context Review Skills

## Summary

Two new Claude skills — a backend bounded-context reviewer and a frontend React/TS feature reviewer — that combine fast structural checks with parallel AI agent semantic analysis. Both produce a merged severity-graded report, write a structured artifact for downstream consumption, and optionally chain into a fix skill.

---

## Problem Frame

The project has three existing verification skills (dddlint, boundary-review, architecture-guardian) that catch structural DDD convention violations via grep. They work well for pattern detection but cannot reason about semantic correctness — whether invariants are enforced, implementations match their contracts, or features are wired end-to-end. Real bugs have shipped because of missing classes, wrong implementations, and integration gaps (features built at one layer but never connected through to the UI). Running all three skills manually is also chore overhead. The frontend has no verification tooling at all.

---

## Requirements

**Structural analysis phase**

- R1. Each skill runs fast, deterministic structural checks before spawning AI agents — reusing the logic from existing skills for backend, and analogous static checks for frontend.
- R2. Backend structural checks cover: layer violations, cross-context dependencies, interface-implementation matching, pattern consistency, wiring verification.
- R3. Frontend structural checks cover: component directory conventions, import discipline, API client usage, state management patterns, test file colocation.

**Semantic analysis phase**

- R4. The skill spawns parallel AI agents (via the Agent tool) for analysis that grep cannot perform.
- R5. Backend spawns four agents: DDD Architecture Verifier, PHP/Symfony Correctness, Integration Completeness, Test Verification.
- R6. Frontend spawns four agents: Component Architecture Verifier, TS/React Correctness, API Integration Completeness, Test Verification.
- R7. Each agent receives project-specific rules and conventions as context so evaluation is grounded in the project's actual patterns, not generic best practices.
- R8. Agents return structured findings that the skill merges and deduplicates into a single report.

**Scope and invocation**

- R9. Backend skill accepts a bounded context name as argument and scopes analysis to `src/<Context>/`.
- R10. Frontend skill accepts a feature name as argument and scopes analysis to `ui/web/src/features/<feature>/`.
- R11. Both skills accept optional flags to broaden scope (e.g., `--all` for full project scan, `--quick` for structural-only).

**Reporting**

- R12. Output is a single merged report with severity levels: error (must fix), warning (tech debt), info (informational).
- R13. The report includes a summary section with counts, a file inventory, and findings grouped by severity.
- R14. Each finding includes file path, line number (when applicable), description, and a suggested remediation or migration path.

**Artifact and handoff**

- R15. After reporting, the skill writes findings to a structured artifact file that any downstream skill can parse.
- R16. The artifact uses a standardized format consumable by other skills without coupling to the analysis skill's internals.
- R17. After writing the artifact, the skill offers to invoke a fix skill directly, passing the artifact path.
- R18. The user can choose which fix skill to invoke (or skip handoff entirely).

---

## Acceptance Examples

- AE1. **Covers R5, R12.** Given the backend skill is invoked on context `Catalog`, it runs structural checks on `src/Catalog/`, spawns four parallel agents, and produces a report listing errors like "Album::create() does not validate title length despite the invariant comment" alongside structural findings from the grep phase.
- AE2. **Covers R6, R12.** Given the frontend skill is invoked on feature `player`, it runs structural checks on `ui/web/src/features/player/`, spawns four parallel agents, and surfaces findings like "usePlayerStore subscribes to WebSocket events but has no cleanup in the useEffect return."
- AE3. **Covers R15, R17.** Given the analysis completes with 3 errors and 5 warnings, the skill writes `docs/reviews/catalog-review-2026-05-10.md` and offers: "Invoke fix skill? Options: /ce-work, /ce-debug, skip."
- AE4. **Covers R10, R11.** Given the frontend skill is invoked with `--all`, it scans all feature directories under `ui/web/src/features/` instead of a single feature.

---

## Success Criteria

- Running one skill invocation catches bugs that previously required manually running dddlint + boundary-review + architecture-guardian and still missed semantic issues.
- The artifact file can be consumed by a downstream skill to implement fixes without the fix skill needing to re-analyze the code.
- Each agent's findings are distinct and non-overlapping — no two agents report the same issue in different words.

---

## Scope Boundaries

- No auto-fixing or code generation within these skills — remediation happens in a separate skill invocation.
- No IntelliJ plugin analysis (Kotlin).
- No running tests or executing code as part of analysis.
- No CI/CD integration.
- No Electron shell analysis — frontend skill focuses on the React web app in `ui/web/`.
- No cross-project analysis — one context or feature per invocation (unless `--all` flag is used).

---

## Key Decisions

- **Hybrid structural + semantic approach**: grep-based checks are fast, deterministic, and free for structural violations. AI agents focus exclusively on semantic reasoning — behavior, invariants, contracts, completeness. This avoids redundant token spend on pattern detection that grep handles in milliseconds.
- **Report-only by design**: the skills produce findings but never modify code. This keeps the analysis safe to run at any time and cleanly separates diagnosis from remediation.
- **Artifact-based handoff**: decoupling findings from fix execution means the same artifact can be consumed by different fix skills, reviewed manually, or archived for later reference.
- **Four agents per skill**: each agent has a tight, non-overlapping scope. Architecture/pattern correctness, implementation correctness, integration completeness, and test quality are distinct analytical dimensions that benefit from dedicated focus.

---

## Dependencies / Assumptions

- The Agent tool supports parallel spawning with structured JSON return values (confirmed by compound-engineering plugin pattern).
- The project's DDD rules in `.claude/rules/ddd-*.md` are comprehensive enough to ground agent analysis.
- The frontend follows the feature-based directory convention consistently enough for a single scoping model.
- Downstream fix skills (e.g., `/ce-work`) can accept an artifact path as input context.

---

## Outstanding Questions

### Deferred to Planning

- [Affects R15, R16] What exact artifact format (markdown sections, JSON, YAML) balances human readability with skill parseability?
- [Affects R6, R7] What frontend-specific rules or conventions should the frontend agents receive as context? No `.claude/rules/` files exist for React/TS currently.
- [Affects R5, R6] Should agent definitions live in `.claude/agents/` or inline in the skill's SKILL.md?
- [Affects R15] Where should artifact files be written — `docs/reviews/`, `.claude/reviews/`, or elsewhere?
