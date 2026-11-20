---
title: Runtime OPcache memory - Plan
type: fix
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Runtime OPcache memory - Plan

## Goal Capsule

**Objective:** Reduce the web server's excessive steady-state memory use while keeping the preview usable for development.

**Means:** Restore the intended CLI opcode cache in the application images (KTD1).

**Authority:** The user's memory report and repository instructions govern scope. The lead owns implementation, verification, local commits and delivery; push only when requested.

**Execution profile:** One change set with three dependent units. Stop if opcode caching breaks the modified Symfony container or if measurements do not establish the claimed reduction.

---

## Product Contract

### Summary and Problem Frame

The preview reports roughly 739 MiB of used PHP heap across four HTTP workers. A live sample measured 180–187 MiB per worker, with six PHP processes accounting for roughly 560 MiB of proportional resident memory (PSS). These are different measurements: the dashboard sums PHP heap, while PSS apportions shared resident pages across processes.

The generated preview uses the image's vanilla development INI, where `opcache.enable_cli` is disabled. The older Compose definition mounts `docker/dev/php.ini`, which enables it. `Dockerfile` installs that project configuration only for CI, so the new development runtime loses the intended opcode cache. The production stage also uses the vanilla production INI.

An isolated production kernel comparison after garbage collection measured 140.85 MiB used heap with CLI OPcache disabled and 25.67 MiB with it enabled, loading the same 7,564 classes. The enabled process used 133.28 MiB of opcode cache. Its single-process PSS was higher, so these results establish a heap reduction, not a whole-server physical-memory reduction. U3 measures the latter across the running workers.

### Requirements

- R1. Application image targets enable the intended OPcache configuration for their long-running CLI PHP processes.
- R2. Development source changes remain observable after the existing worker refresh lifecycle.
- R3. The modified Symfony container boots correctly from both cold and warm caches with OPcache enabled.
- R4. Verification reports PHP heap and physical memory separately under comparable worker counts and workloads.
- R5. Preview refresh preserves local data and leaves the persistent instance untouched.

### Scope Boundaries

This change covers the image configuration that controls CLI OPcache, its runtime validation, and the current preview refresh. It does not change worker counts, transcode capacity, service-pool retention, application memory limits or the dashboard's metric contract. Relabelling dashboard units and expanding process diagnostics are separate presentation work; neither addresses the measured heap cost.

---

## Planning Contract

### Key Technical Decisions

- KTD1. Install a focused OPcache configuration in the common application runtime stage so development and production inherit it. Keep development timestamp validation enabled and avoid importing unrelated development INI settings into production. The source evidence is `Dockerfile`'s runtime, production, CI and dev-runtime stages, `docker/dev/php.ini`, and `docker-compose.yml`'s legacy PHP INI mount. Covers R1–R2.
- KTD2. Reuse the existing opcode-cache capacity of 256 MiB, which accommodates the measured 133.28 MiB cold production cache. Preserve existing PHP memory limits. Covers R1.
- KTD3. Validate actual runtime configuration and cold/warm application boots rather than testing Dockerfile text alone. Include the ZEngine-modified container because `packages/swoole-bundle/src/Bridge/Symfony/Container/Modifier/Builder/Symfony63PlusBuilder.php` alters container execution. Covers R1–R3.
- KTD4. Compare the preview's summed worker heap and all PHP-process PSS before and after refresh; record worker count, age and workload with each sample. Record opcode-cache occupancy from isolated boot probes, since the live diagnostics do not expose it. The isolated comparison is evidence for pursuing the fix, not a substitute for the running-server result. Covers R4–R5.

### Risks and Assumptions

OPcache stores immutable code in shared memory and can increase a single process's PSS. Physical savings depend on the server's fork and loading behavior, so the live result must be measured. The existing development refresh lifecycle remains responsible for replacing workers that already loaded changed classes; timestamp validation cannot redefine an existing class.

The production image has no intended mutable source overlay. Cold and warm tests must still exercise the generated container, its proxy code and runtime class changes. A failed compatibility check blocks delivery rather than introducing an unmeasured fallback.

---

## Implementation Units

### U1. Restore runtime opcode-cache configuration

- **Intent:** Make the application image targets load the focused OPcache settings described by KTD1–KTD2.
- **Scope:** `Dockerfile`, `docker/php/opcache.ini`, and the existing `docker/dev/php.ini` to remove the extracted duplicate settings.
- **Approach:** Extract the existing OPcache block into the common configuration and load it before target-specific INI files; retain timestamp checks, JIT settings and effective values.
- **Verification:** An executable image check confirms CLI OPcache is enabled and compiles a temporary PHP file that appears in the cache. Runtime, dev-runtime and production retain adequate cache capacity; CI retains its required configuration. Covers R1–R2.

### U2. Verify application compatibility and memory attribution

- **Intent:** Establish that cached code reduces retained heap without breaking cold or warm application startup.
- **Scope:** The existing image smoke or qualification checks, plus a bounded reproducible probe if no existing check covers this behavior.
- **Approach:** Exercise the compiled Symfony container, proxy generation and ZEngine modifications in development and production modes; record heap, cache occupancy and resident memory separately.
- **Verification:** Cold and warm boots succeed and the same class workload uses less PHP heap with OPcache enabled. Avoid an exact-byte regression assertion. Covers R1–R4.
- **Execution note:** This is configuration work; prefer runtime smoke verification over unit tests that mirror INI contents.

### U3. Refresh the preview and record the result

- **Intent:** Apply the corrected image to the isolated preview and report measured results.
- **Scope:** Preview lifecycle, the existing HTTP-worker memory report or a focused follow-up report, and `ROADMAP.md`.
- **Approach:** Use the documented preview refresh lifecycle, repeat the baseline workload, and collect all HTTP workers' heap plus all PHP processes' PSS after startup and after an idle interval.
- **Verification:** The preview serves HTTP successfully; all four workers answer diagnostics; measured heap reduction persists after idle; physical-memory changes and limitations are recorded. Covers R4–R5.

---

## Verification Contract

Use the repository's CI image for applicable automated checks. Build and inspect the affected application image targets, then run bounded cold/warm boot checks with OPcache enabled. Validate source-edit refresh in the isolated development instance. Follow `docs-book/part-2-developer-guide/getting-started/development-environment.md` for the preview lifecycle and use its reported URL for HTTP checks.

Retain reproducible probe sources and non-sensitive measurement summaries in tracked documentation. A successful result states worker count, heap totals, PSS totals and test counts. Do not call a lower dashboard total a physical-memory saving without the PSS comparison.

## Definition of Done

R1–R5 are verified, the preview is healthy, and `ROADMAP.md` records the delivered changes and checkpoints. The lead completes simplification and code review, applies justified findings, and creates local commits. No memory-reduction claim exceeds the measurements.
