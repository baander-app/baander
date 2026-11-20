---
title: TypePHP performance comparison - Plan
type: feat
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# TypePHP performance comparison - Plan

## Goal Capsule

Measure the existing packaged executable against ordinary PHP using equivalent application workloads, then publish the measured results on `ui/site`. Work continues through implementation and local verification on `feat/typephp-performance-comparison`, with local commits and no push. The lead owns integration, authoritative checks and `ROADMAP.md`.

## Product Contract

### Summary

Deliver reproducible measurements and a public report that identifies the tested artifacts, settings, workloads and limits. A slower or indistinguishable result is a valid outcome.

### Problem Frame

The application has passed functional qualification, but its correctness-suite and runtime-drill durations do not establish comparative performance. `tools/typephp/README.md` describes embedded PHP bytecode executed through ZendVM; this comparison must measure that packaging path rather than imply native translation of application code.

### Requirements

- R1. Measure the current TypePHP executable and ordinary PHP against the same source and production dependency tree, with equivalent commands, inputs, runtime settings and completed work.
- R2. Include repeated fresh-process application startup and a sustained deterministic workload that executes application or dependency code embedded in the package. Separate startup from the sustained timed region.
- R3. Preserve raw observations, provenance, methodology and computed summaries in tracked files outside `var/`. Publish actual measurements, including unfavorable results and measured variability.
- R4. Add a discoverable report to `ui/site` with accessible tables, workload-specific conclusions and reproduction instructions. Record delivered scope and exact verification counts in `ROADMAP.md`.

### Scope Boundaries

This work measures packaging performance on the available Linux host. It introduces no application optimization, compatibility claim for other environments, or performance release gate. HTTP throughput, database workloads, production deployment, `ui/rn` and Essentia are outside this comparison: a deterministic sustained application workload provides the requested long-running comparison without adding service orchestration. The report must state that this does not establish HTTP or worker throughput. Do not reset local data or replace the persistent instance.

## Assumptions

The qualified executable and its matching physical resource tree remain available. Existing image tags are discovery hints, not provenance; implementation verifies immutable image IDs, executable hashes and source/dependency identities before collecting samples. Runtime differences that cannot be equalized remain explicit confounders.

## Planning Contract

### Key Technical Decisions

- KTD1. Compare ordinary PHP from the matched application image with the packaged executable in the same disposable filesystem when feasible; otherwise use disposable matched containers mounting identical inputs at `/var/www/html`. Identify both executable hashes and their actual versions, extensions and effective INI. Equalize production environment, debugging/telemetry, memory limit, CLI OPcache, JIT, assertions and optimization settings. Disable the qualification-only prepend for both. Static versus shared extension linkage remains a reported difference. This prevents image or development-loader differences from masquerading as packaging gains.
- KTD2. Measure real fresh-process console discovery and independent operator help, plus a deterministic sustained workload using embedded application value objects and dependency code through canonical Composer autoload. Verify matching outputs and operation counts before accepting timing. An interpreter-only loop can be context, but cannot satisfy R2. Reuse the same driver in both variants; avoid entropy, network and disk mutation in the sustained timed region.
- KTD3. Warm the production application cache identically, then label fresh-process startup as startup with a warm application/filesystem cache. Do not call this cold-machine boot. Run at least three untimed warmups and 30 measured samples per variant and workload, alternating ordinary/package order. Calibrate sustained batches once so each sample is long enough to measure reliably, then freeze the operation count for both variants. Record wall time, and CPU and peak RSS when the measurement method supports them. Exclude container startup and host-side Docker invocation overhead from timing.
- KTD4. Keep per-sample records and a run manifest in `tools/typephp/benchmarks/results/`; retain the exact harness, workload parameters, host/CPU/kernel, UTC run date, checkout and dirty-source identity, lockfile hashes, image IDs, binary hashes, warmups, ordering and INI/extension records. Compute median, nearest-rank p95, range and package/ordinary ratios from those records. Define each ratio's direction beside its values. Preserve failed observations with their status and reason; a failed workload has no valid speed ratio. Report the method used for any repeat or replacement run.
- KTD5. Publish the maintained report through the existing reference-page mechanism. `ui/site/reference/runtime-performance.php` supplies metadata and `ui/site/menu.php` exposes `/runtime-performance/`; the source is `tools/typephp/benchmarks/README.md`. Reuse `ui/site/templates/reference.php` and existing build/search/sitemap behavior. Raw results remain linked repository evidence. This needs no new frontend framework or chart subsystem.

### High-Level Technical Design

The intended evidence flow is:

```text
matched runtime identities + fixed workload inputs
    -> correctness comparison + alternating measured samples
    -> tracked run manifest and raw samples
    -> derived summary in the maintained report
    -> existing reference-page builder -> /runtime-performance/
```

The sampling lifecycle is prepare, verify equivalence, warm, measure, validate records and report. A runtime mismatch or incorrect workload result stops acceptance; it produces a documented failed comparison rather than a speed claim.

## Implementation Units

### U1. Reproducible equivalent-workload harness

**Requirements:** R1, R2; KTD1–KTD3. **Dependencies:** none.

**Files:** new harness and deterministic driver under `tools/typephp/benchmarks/`; `tools/typephp/README.md` for the entry link. Follow existing qualification dispatch in `tools/typephp/application-entry.php` and runtime records in `tools/typephp/runtime-lock.json`. Do not refactor the application or runtime drills.

The packaging guide labels its older shutdown-qualified executable as current,
although the package-organization checkpoint in `ROADMAP.md` records a later
verified build. Preserve that historical qualification evidence and name its
checkpoint explicitly when adding the performance-report link.

Review reproduced a source-provenance gap: an untracked application file could
pass the initial checkout comparison. Reject application paths absent from the
named source commit while allowing generated production vendor files. Add the
CLI regression in `tools/typephp/benchmarks/test_run.py` and recheck the measured
snapshot; this preflight correction does not change the timed workloads.

**Verification:** run small equivalence probes before timing: both commands exit successfully, console/operator outputs match after any explicitly documented nondeterministic fields, and the sustained workload has the same operation count and checksum. Test a deliberately failed child and incorrect checksum: neither may yield an accepted result or ratio. Exercise both runtime selections and confirm the timed child uses the expected executable. Preserve the successful harness command and exact workload settings in the report. Use focused harness checks rather than rerunning application qualification for benchmark-only changes.

### U2. Actual measurements and durable report

**Requirements:** R2, R3; KTD2–KTD4. **Dependencies:** U1.

**Files:** `tools/typephp/benchmarks/results/` and `tools/typephp/benchmarks/README.md`.

Collect the full sample set on the available host. Preserve immutable provenance and raw observations before writing conclusions. Explain startup versus sustained timing, cache state, shared-host noise, static/shared linkage and the absence of HTTP/worker coverage. Give the commands needed to reproduce collection and recompute summaries. Keep source identity reproducible even when measurement precedes the final commit; hash relevant dirty files instead of implying the checkout was clean.

**Verification:** independently recompute every published median, p95 and ratio from the tracked observations; check counts, units, finite positive timings and checksum equivalence. Confirm that unsuccessful observations are excluded explicitly and no workload silently disappears. No minimum speedup is required.

### U3. Publish the measured site page and completion evidence

**Requirements:** R3, R4; KTD5. **Dependencies:** U2.

**Files:** `ui/site/reference/runtime-performance.php`, `ui/site/menu.php`, `ui/site/README.md`, `scripts/tests/php/Website/ReferencePagesTest.php`, `scripts/tests/php/Website/SiteMenuTest.php`, `ui/site/tests/website.spec.ts` or the existing reference-page browser test, and `ROADMAP.md`.

Follow `ui/site/reference/deploy.php` for the page descriptor. Adjust only tests whose existing exact reference-page inventory needs the added page; add meaningful coverage that the report appears with its results table, methodology and evidence links. Apply the repository documentation voice and run prose-fix on new or changed prose. Record every delivered item, workload sample counts, test counts and static results in the roadmap, citing tracked evidence inline.

**Verification:** build the website through its existing runner; verify `/runtime-performance/` navigation, search/sitemap inclusion, valid links, readable tables on narrow and wide viewports, and useful content without JavaScript. Run affected PHP tooling tests, website rendering fixtures and the dedicated site browser suite. Run the tooling formatter and applicable targeted static checks for changed PHP. Run simplification and code review, apply justified findings, then commit locally. Any verified defect in touched paths joins these units; unrelated work needs a stated reason for separate handling.

## Verification Contract

Use the existing CI-image runners for affected PHP tooling tests and static checks, then the website build, rendering fixtures and dedicated browser suite documented in `ui/site/README.md`. Benchmark verification checks equivalent outputs, accepted sample counts and independent recomputation from tracked raw records. Record exact counts and outcomes in `ROADMAP.md`; benchmark-only changes do not require repeating application qualification.

## Definition of Done

The result is complete when both runtimes have accepted, equivalent startup and sustained measurements, the tracked evidence reproduces all published figures, the built site presents the measured report, and `ROADMAP.md` records the completed checks. If a prerequisite prevents a trustworthy comparison, preserve the failure and explain exactly which measurements remain unavailable. No planning-time blocking question remains; executable identity, feasible instrumentation and final measurements are execution checks.

## Sources

- User request: compare the TypePHP build with normal runs and document the results afterward on `ui/site`.
- `docs/plans/2026-11-19-1159-feat-typephp-setup-plan.md`: antecedent packaging and functional qualification; this plan adds performance comparison scope.
- `tools/typephp/README.md`, `tools/typephp/application.yml`, `tools/typephp/runtime-lock.json`: packaging semantics, canonical resource tree and runtime identity.
- `ui/site/README.md`, `ui/site/reference/deploy.php`, `packages/baander-tooling/src/Website/ReferencePages.php`: reference-page publishing and static-site checks.
- `scripts/tests/php/Website/ReferencePagesTest.php`, `ui/site/tests/website.spec.ts`: existing website verification patterns.
