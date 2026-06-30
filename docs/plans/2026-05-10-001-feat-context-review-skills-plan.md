---
title: Context Review Skills
type: feat
status: active
date: 2026-05-10
origin: docs/brainstorms/2026-05-10-context-review-skills.md
---

# Context Review Skills

## Summary

Two new Claude skills — `context-review-backend` and `context-review-frontend` — that analyze a single bounded context or React feature, run fast structural checks then parallel AI semantic agents, produce a merged severity-graded report, write a structured artifact to `docs/reviews/`, and offer handoff to a fix skill. Includes a new frontend rules document (`.claude/rules/frontend.md`) for grounding frontend agent analysis in project conventions.

---

## Problem Frame

Structural grep-based skills (dddlint, boundary-review, architecture-guardian) catch convention violations but cannot reason about semantic correctness. Real bugs have shipped from missing classes, wrong implementations, and integration gaps. The frontend has no verification tooling. Running three skills manually is chore overhead.

---

## Requirements

- R1. Each skill runs fast deterministic structural checks before spawning AI agents (origin: R1)
- R2. Backend structural checks cover layer violations, cross-context dependencies, interface-implementation matching, pattern consistency, wiring verification (origin: R2)
- R3. Frontend structural checks cover component conventions, import discipline, API client usage, state management patterns, test file colocation (origin: R3)
- R4. Skills spawn parallel AI agents via the Agent tool for semantic analysis (origin: R4)
- R5. Backend spawns four agents: DDD Architecture Verifier, PHP/Symfony Correctness, Integration Completeness, Test Verification (origin: R5)
- R6. Frontend spawns four agents: Component Architecture Verifier, TS/React Correctness, API Integration Completeness, Test Verification (origin: R6)
- R7. Each agent receives project-specific rules as context (origin: R7)
- R8. Agent findings merge and deduplicate into a single report (origin: R8)
- R9. Backend skill accepts a bounded context name, scopes to `src/<Context>/` (origin: R9)
- R10. Frontend skill accepts a feature name, scopes to `ui/web/src/features/<feature>/` (origin: R10)
- R11. Both skills accept `--quick` (structural-only) and `--all` (scan everything) flags (origin: R11)
- R12. Output is a single merged report with severity levels: error (must fix), warning (tech debt), info (informational) (origin: R12)
- R13. Report includes summary counts, file inventory, findings grouped by severity (origin: R13)
- R14. Each finding includes file path, line number, description, suggested remediation (origin: R14)
- R15. Skills write findings to a structured artifact file (origin: R15)
- R16. Artifact format is standardized and consumable by downstream skills (origin: R16)
- R17. Skills offer to invoke a fix skill after writing the artifact (origin: R17)
- R18. User chooses which fix skill to invoke or skips handoff (origin: R18)

**Origin acceptance examples:** AE1 (backend on Catalog), AE2 (frontend on player), AE3 (artifact + handoff), AE4 (frontend --all)

---

## Scope Boundaries

- No auto-fixing or code generation within these skills
- No IntelliJ plugin analysis (Kotlin)
- No running tests or executing code
- No CI/CD integration
- No Electron shell analysis — frontend skill focuses on React web app in `ui/web/`
- No cross-project analysis — one context/feature per invocation (unless `--all`)

### Deferred to Follow-Up Work

- Refactoring existing dddlint/boundary-review/architecture-guardian to delegate to the new backend skill (separate cleanup)
- Pre-commit hook integration for automated review on commit

---

## Context & Research

### Relevant Code and Patterns

- Existing skills: `.claude/skills/dddlint/SKILL.md`, `.claude/skills/boundary-review/SKILL.md`, `.claude/skills/architecture-guardian/SKILL.md` — structural check logic to embed
- DDD rules: `.claude/rules/ddd-domain-models.md`, `ddd-repositories.md`, `ddd-cqrs.md`, `ddd-ports.md` — agent context for backend
- Compound-engineering agent pattern: inline agent prompts in SKILL.md, parallel Agent tool dispatch, structured JSON returns
- Frontend patterns: feature-based directories (`ui/web/src/features/<name>/`), Zustand stores, `use-` prefixed hooks, shared axios instance, shadcn/ui components

### Institutional Learnings

- Convention: manual object construction in tests (no Zenstruck Foundry)
- Convention: `yarn` for UI, never `npm`
- Convention: UUID v7 for primary keys, TEXT for strings, JSONB for JSON

---

## Key Technical Decisions

- **Agent definitions inline in SKILL.md**: No separate `.agent.md` files or `.claude/agents/` directory. Each agent is a named section within the skill that gets passed as the prompt to the Agent tool. Simpler, self-contained, no new infrastructure. Rationale: project has no existing agent definition convention; compound-engineering uses plugin-internal agents that don't map to project-local files.
- **Artifact format: markdown with structured sections**: Human-readable in any editor, downstream skills parse section headers, git-friendly for diffing. JSON would be more parseable but hostile to human review and manual editing.
- **Artifact location: `docs/reviews/`**: Consistent with existing `docs/` structure, discoverable, not hidden in `.claude/` internals.
- **Frontend rules document: `.claude/rules/frontend.md`**: Mirrors the existing `.claude/rules/ddd-*.md` pattern. Agents receive it as context just like backend agents receive DDD rules.
- **Structural phase embeds existing logic directly**: No delegation to dddlint/boundary-review/architecture-guardian as separate skill invocations. The new skill contains the check logic inline — avoids cross-skill coordination overhead and ensures the structural phase is self-contained.

---

## Open Questions

### Resolved During Planning

- Artifact format → Markdown with structured sections (R16)
- Agent definitions location → Inline in SKILL.md
- Artifact file location → `docs/reviews/`
- Frontend agent rules → New `.claude/rules/frontend.md` document
- Flag set finalized to exactly `--quick` and `--all` (R11 — origin used "e.g." for examples; plan fixes the set)

### Deferred to Implementation

- Exact agent prompt wording for each of the 8 agents — directional guidance is in the plan, final wording emerges during implementation
- Exact structural check commands — reuse patterns from existing skills but may need adjustment for new report format
- Whether the frontend skill should also analyze `ui/web/src/shared/` when reviewing a feature that depends on shared components

---

## Implementation Units

### U1. Shared artifact format and directory convention

**Goal:** Establish the artifact format and output directory that both skills write to.

**Requirements:** R15, R16

**Dependencies:** None

**Files:**
- Create: `docs/reviews/.gitkeep`

**Approach:**
- Create `docs/reviews/` directory with a `.gitkeep`
- The artifact format is markdown with these sections: Summary (counts by severity), Errors, Warnings, Info, File Inventory, Agent Findings (per-agent subsections)
- Filename convention: `<scope>-review-YYYY-MM-DD.md` (e.g., `catalog-review-2026-05-10.md`, `player-review-2026-05-10.md`)
- Each finding has: file path, line number (optional), severity, description, remediation hint, source agent (for semantic findings) or "structural" (for grep-based findings)

**Patterns to follow:**
- Report format from `.claude/skills/boundary-review/SKILL.md` Step 7 (the existing report template)

**Test scenarios:**
- Test expectation: none — this unit creates a directory and defines a convention. The format is validated implicitly when both skills produce artifacts.

**Verification:**
- `docs/reviews/.gitkeep` exists
- Both skill SKILL.md files reference the same artifact format specification

---

### U2. Frontend convention rules document

**Goal:** Create a rules document that frontend agents receive as context, analogous to the DDD rules for backend agents.

**Requirements:** R7

**Dependencies:** None

**Files:**
- Create: `.claude/rules/frontend.md`

**Approach:**
- Document the frontend patterns discovered during research:
  - Feature directory structure (components/, hooks/, pages/, stores/)
  - File naming conventions (PascalCase components, `use-` hooks, `-store` stores, `-api` API modules)
  - Component patterns (functional components with TypeScript interfaces, shadcn/ui usage, Tailwind with `cn()`)
  - Hook patterns (useEffect cleanup, store selectors, useCallback for handlers)
  - State management patterns (Zustand with persist middleware, explicit state interfaces)
  - API client patterns (shared axios instance, DPoP auth, error handling)
  - Import discipline (`@/` alias, no relative paths across features)
  - Testing patterns (Vitest + Testing Library, though currently no tests exist)
- Format mirrors existing `.claude/rules/ddd-*.md` files: frontmatter with paths, then pattern descriptions with code examples and common mistakes

**Patterns to follow:**
- `.claude/rules/ddd-domain-models.md` — same structure: frontmatter with paths, pattern sections with examples, "Common Mistakes" section

**Test scenarios:**
- Test expectation: none — this is a reference document. Validated implicitly when frontend agents use it to ground their analysis.

**Verification:**
- File exists at `.claude/rules/frontend.md`
- Contains patterns for all areas listed above (component, hook, store, API, import, test conventions)

---

### U3. Backend context-review skill

**Goal:** Create the backend review skill with structural checks, 4 inline agent definitions, orchestration, and handoff.

**Requirements:** R1, R2, R4, R5, R7, R8, R9, R11, R12, R13, R14, R15, R16, R17, R18

**Dependencies:** U1

**Files:**
- Create: `.claude/skills/context-review-backend/SKILL.md`

**Approach:**

The SKILL.md defines the complete workflow in a single file:

1. **Input parsing** — Extract context name from `$ARGUMENTS`. Handle `--quick` and `--all` flags. Validate context exists under `src/<Context>/`.

2. **Structural phase** — Embed check logic from dddlint + boundary-review + architecture-guardian as bash commands that produce findings in the standardized format. Checks: layer violations, cross-context dependencies, interface-implementation matching, pattern consistency, wiring, aggregate root patterns, entity/model separation, CQRS conventions. Group findings by severity (error/warning/info).

3. **Semantic phase** — Spawn 4 agents in parallel via the Agent tool. Each agent receives:
   - The bounded context scope (file list from structural phase inventory)
   - The relevant DDD rules from `.claude/rules/ddd-*.md` as context
   - A structured prompt defining its role, what to look for, what to ignore, and output format
   - Instructions to return JSON findings with: file, line, severity, title, description, remediation

   **Agent 1: DDD Architecture Verifier**
   - Focus: layer integrity, pattern correctness, domain invariant enforcement, state object completeness
   - Checks: Are create() methods enforcing invariants? Do state objects have all fields? Are mutation methods consistent? Do repositories map fields correctly in toDomain()?
   - Ignores: Code style, naming opinions, performance

   **Agent 2: PHP/Symfony Correctness**
   - Focus: Logic bugs, type safety, error handling, framework convention adherence
   - Checks: Null propagation, broken error propagation, incorrect state transitions, missing edge cases in mutation methods, Symfony service configuration issues
   - Ignores: DDD pattern violations (that's Agent 1), test quality (that's Agent 4)

   **Agent 3: Integration Completeness**
   - Focus: End-to-end feature wiring, missing classes, disconnected features
   - Checks: Does every port have a controller endpoint? Does every endpoint reach the domain? Are all referenced classes present? Are domain events dispatched and handled? Missing services.yaml wiring that structural phase didn't catch?
   - Ignores: Correctness of implementations (that's Agent 2), pattern conventions (that's Agent 1)

   **Agent 4: Test Verification**
   - Focus: Test quality, coverage gaps, assertion strength
   - Checks: Untested branches in new code, weak assertions, brittle implementation-coupled tests, missing error path coverage, manual object construction convention adherence
   - Ignores: Test style preferences, coverage metrics

4. **Merge phase** — Combine structural findings + 4 agent JSON returns. Deduplicate by (file, line, description-similarity). Assign severity per the standardized scale. Produce the merged report.

5. **Report phase** — Print the merged report to output. Write the artifact to `docs/reviews/<context>-review-YYYY-MM-DD.md`.

6. **Handoff phase** — Present the artifact path and offer to invoke a fix skill. Options: `/ce-work docs/reviews/<artifact>`, `/ce-debug`, or skip.

**Execution note:** Build structural phase first, verify it produces findings, then add semantic agents.

**Test scenarios:**
- Happy path: invoke on a well-structured context (e.g., `Catalog`) — structural phase runs, 4 agents spawn in parallel, report prints, artifact writes to `docs/reviews/`, handoff prompt appears
- Quick mode: `--quick` flag skips agent spawning, only structural phase runs
- All mode: `--all` flag scans all contexts instead of one
- Invalid context: nonexistent context name produces error message and stops
- Deduplication: structural phase and an agent both flag the same issue — merge phase deduplicates to a single finding with both sources attributed
- Covers AE1: Catalog context produces semantic findings alongside structural ones
- Covers AE3: Artifact writes to `docs/reviews/` and handoff prompt lists fix skill options

**Verification:**
- Skill invocation on any bounded context produces a complete report with structural + semantic sections
- `docs/reviews/` contains the artifact after invocation
- Handoff prompt appears with artifact path

---

### U4. Frontend context-review skill

**Goal:** Create the frontend review skill with structural checks, 4 inline agent definitions, orchestration, and handoff.

**Requirements:** R1, R3, R4, R6, R7, R8, R10, R11, R12, R13, R14, R15, R16, R17, R18

**Dependencies:** U1, U2

**Files:**
- Create: `.claude/skills/context-review-frontend/SKILL.md`

**Approach:**

Mirrors U3 structure but with frontend-specific checks and agents:

1. **Input parsing** — Extract feature name from `$ARGUMENTS`. Handle `--quick` and `--all`. Validate feature exists under `ui/web/src/features/<feature>/`.

2. **Structural phase** — Frontend-specific checks:
   - File naming conventions (PascalCase components, `use-` hooks, `-store` stores)
   - Import discipline (`@/` alias usage, no cross-feature relative imports)
   - API client usage (imports from shared axios instance, not custom fetch)
   - Store patterns (Zustand with proper persist config)
   - Component patterns (TypeScript interfaces for props, shadcn/ui usage)
   - Test file colocation (test files alongside or in `__tests__/`)
   - Missing barrel exports (`index.ts`) where expected

3. **Semantic phase** — 4 parallel agents. Each receives the feature scope, `.claude/rules/frontend.md` content, and a structured role prompt.

   **Agent 1: Component Architecture Verifier**
   - Focus: Component structure, prop interfaces, composition patterns, shadcn/ui usage
   - Checks: Are components properly typed? Are there god components doing too much? Missing memoization for expensive renders? Improper key usage in lists?
   - Ignores: CSS class ordering, import order

   **Agent 2: TS/React Correctness**
   - Focus: Logic bugs, type safety, hook rules, state management bugs
   - Checks: Missing useEffect cleanup, stale closures in useCallback, incorrect hook dependency arrays, type assertions hiding errors, unhandled promise rejections in async components
   - Ignores: Component architecture (Agent 1), test quality (Agent 4)

   **Agent 3: API Integration Completeness**
   - Focus: Backend-frontend contract alignment, missing API hooks, disconnected features
   - Checks: API endpoints called that don't exist in backend? Backend endpoints with no frontend consumer? Request/response type mismatches? Missing error handling for API calls? Features with UI but no backend integration?
   - Ignores: Component correctness (Agent 2), architecture (Agent 1)

   **Agent 4: Test Verification**
   - Focus: Test quality, coverage gaps, testing patterns
   - Checks: Missing tests for hooks, untested error states, weak assertions, missing user interaction tests, improper mock patterns
   - Ignores: Test style preferences, coverage metrics

4. **Merge, Report, Artifact, Handoff** — Identical flow to U3's merge/report/artifact/handoff phases. Artifact: `docs/reviews/<feature>-review-YYYY-MM-DD.md`.

**Execution note:** Build after U3 is complete so the orchestration pattern is proven. Adapt rather than reinvent.

**Test scenarios:**
- Happy path: invoke on `player` feature — structural phase checks file naming/imports, 4 agents spawn, report and artifact produced
- Quick mode: `--quick` skips agents
- All mode: `--all` scans all feature directories
- Invalid feature: nonexistent feature name produces error and stops
- Deduplication: overlapping findings from agents merge to single entry
- Covers AE2: player feature produces semantic finding about useEffect cleanup
- Covers AE3: artifact writes and handoff prompt appears
- Covers AE4: `--all` scans all features

**Verification:**
- Skill invocation on any feature produces a complete report
- `docs/reviews/` contains the artifact
- Handoff prompt appears

---

## System-Wide Impact

- **Interaction graph:** Both skills are read-only — no callbacks, middleware, or entry points are affected. They only read and analyze code.
- **New skill registration:** Two new skills appear in the skill catalog. Existing skills are untouched.
- **New rules document:** `.claude/rules/frontend.md` is a new reference file. No existing rules are modified.
- **New artifact directory:** `docs/reviews/` is a new output location. No existing directories are affected.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| Agent prompts produce overlapping findings | Deduplication by file+line+description similarity in merge phase. Agent prompts explicitly state what to ignore from other agents' domains. |
| Structural checks miss edge cases in frontend patterns | Frontend rules document (U2) provides the ground truth. Structural checks follow its patterns strictly. |
| Agent token cost too high for large contexts | `--quick` flag provides structural-only mode. Default scope is one context/feature, not the whole project. |
| Artifact format not parseable by downstream skills | Markdown format uses consistent section headers that downstream skills can grep for. Documented convention in U1. |

---

## Sources & References

- **Origin document:** `docs/brainstorms/2026-05-10-context-review-skills.md`
- Existing backend skills: `.claude/skills/dddlint/SKILL.md`, `.claude/skills/boundary-review/SKILL.md`, `.claude/skills/architecture-guardian/SKILL.md`
- DDD rules: `.claude/rules/ddd-domain-models.md`, `ddd-repositories.md`, `ddd-cqrs.md`, `ddd-ports.md`
- Compound-engineering agent pattern: `.claude/plugins/cache/compound-engineering-plugin/compound-engineering/3.7.1/skills/ce-code-review/SKILL.md`
- Compound-engineering agent examples: `.claude/plugins/cache/compound-engineering-plugin/compound-engineering/3.7.1/agents/ce-correctness-reviewer.agent.md`, `ce-testing-reviewer.agent.md`
- Frontend structure: `ui/web/src/features/`, `ui/web/src/shared/`, `ui/web/package.json`
