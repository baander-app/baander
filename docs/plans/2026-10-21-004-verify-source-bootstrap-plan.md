---
title: Verify the source installation after the Xdebug repair - Plan
type: docs
date: 2026-10-21
artifact_contract: ce-unified-plan/v1
product_contract_source: user-directed-follow-up
execution: code
---

# Verify the source installation after the Xdebug repair - Plan

## Goal Capsule

Objective: A reader can assess whether the documented source installation produces
an instance with a working browser, administrator login, background workers and
sample library.

Means: Repeat the outstanding disposable bootstrap and correct documentation from
its evidence (KTD1).

Authority: The requested verification completes the runtime checks in
`docs/plans/2026-10-21-001-docs-user-facing-readme-plan.md`. The expanded introduction
in `docs/plans/2026-10-21-002-docs-expand-project-readme-plan.md` remains the README's
scope. The merged Xdebug repair `d35c74e5` supplies the starting point.

Stop conditions: Report application blockers with bounded evidence and identify
checks they prevent; do not redesign deployment or claim an incomplete walkthrough
passed. The lead integrates documentation, records the checkpoint and commits
locally. Push requires a separate request.

## Product Contract

### Summary

The source guide now reports the repaired image build, but frontend loading,
administrator login, worker startup and scanning remain unverified. Repeat those
checks against the repaired source, preserving the historical failed attempt.

### Requirements

- R1. Verify the guide in a disposable checkout containing the merged repair, with
  isolated service identities, credentials, networks, storage and published ports.
- R2. Do not reuse or reset existing application databases, Redis or media. Use
  disposable media and remove only resources whose ownership this run establishes.
- R3. Follow the documented configuration, image build, PHP dependencies, guarded
  main-database migration, OAuth setup, administrator creation and frontend build.
- R4. Open the built frontend in a browser over the configured HTTPS address and
  authenticate as the newly created administrator; asset compilation alone is
  insufficient evidence.
- R5. Deploy the current version-4 worker pair through the authoritative host
  controller, then establish startup and operational health from runtime evidence.
  Controller status reporting `readiness: "not_checked"` is insufficient evidence.
- R6. Create and scan a small disposable library, then verify the discovered media
  appears in the administrator's browser session. Distinguish the synchronous
  console scan from evidence that workers process background work.
- R7. Correct only documentation contradicted by the walkthrough. Record successful,
  failed and blocked scenarios, source revision and verification limits in
  `ROADMAP.md`; retain the earlier bootstrap failure as history.
- R8. Review documentation and apply justified findings, run preservation and
  whitespace checks, and commit locally without pushing.
- R9. If pinned Yarn rejects only incompatible lockfile metadata, permit the
  smallest metadata correction in `ui/web/yarn.lock`. Preserve dependency resolution
  and prove the correction with immutable install, type-check and frontend build.
- R10. Repair the development proxy so generated API origins retain the browser
  HTTPS scheme and authority, including a custom published TLS port. Replace
  client-supplied forwarding authority and scheme at the proxy boundary.
- R11. Document configuring only the actual nginx source address as trusted in
  the source-installation topology, including restarting app after changes and
  refreshing the address after proxy recreation. Preserve empty trust by default
  and direct-client spoofing protections.
- R12. Prove the proxy repair with runtime header forwarding and trusted/untrusted
  URL and DPoP regression checks, then repeat the real browser administrator login.

### Scope Boundaries

The deliverable is verification evidence, documentation corrections and the bounded
lockfile correction in R9 and development proxy repair in R10–R12. The user
explicitly authorized fixing the observed HTTPS login blocker. This work adds no
installer, application feature, deployment redesign or compatibility path.
Transient configuration in the disposable checkout may isolate the documented
stack. The verification environment may copy the checkout into a run-owned named
volume when Docker cannot bind the tool filesystem. It may use the existing worker
harness's trusted controller container when host PHP lacks `posix`; record this as
an adaptation rather than proof of the literal host-controller procedure.
A discovered application defect is a reported blocker unless separately
authorized for repair. Host prerequisite availability is an execution-time question;
report any inability to provide a disposable controller runtime meeting the guide.

## Planning Contract

### Key Technical Decisions

- KTD1. Use the getting-started guide as the walkthrough and the worker deployment
  reference as the owner of the version-4 manifest. Reuse the prior plan's isolation
  approach; a Compose project name does not isolate fixed names, ports or external
  volumes (R1–R6).
- KTD2. Establish resource ownership before starting services. Keep generated secrets
  and raw logs outside tracked files, redact credentials from reported evidence,
  and retain only the evidence needed to distinguish each verification outcome
  (R1–R2, R7).
- KTD3. Separate environmental prerequisites from application failures. Resolve
  disposable setup issues within the existing procedure; stop at application
  blockers and document their effect on remaining checks (R3–R7).
- KTD4. Preserve document baselines outside the repository before corrections.
  Apply `prose-fix` to changed prose and inspect every audit delta against the
  intended factual correction (R7–R8).
- KTD5. Pinned Yarn 4.14.1 rejects the current lockfile metadata version before
  installation. Evaluate a metadata-only correction in the disposable checkout;
  retain it only if immutable installation and frontend checks pass without
  dependency-resolution changes (R9).
- KTD6. Preserve the incoming HTTP authority with `$http_host` for `Host` and
  `X-Forwarded-Host` in all three header blocks of the active development template;
  retain proxy-derived HTTPS scheme and suppress incoming `X-Forwarded-Port`.
  Clearing that header lets the preserved authority determine its port and avoids
  trusting an attacker-selected port (R10–R12).
- KTD7. Configure trust from the actual nginx peer address rather than changing
  framework or Swoole trust defaults. Both layers already share `TRUSTED_PROXIES`;
  the missing setting explains the observed HTTP origin, and the template
  `$host` values explain the missing published port (R10–R12).

### Sources and Dependencies

The outstanding checkpoints are recorded under Source installation documentation in
`ROADMAP.md`. Installation ordering comes from
`docs-book/part-1-operator-guide/getting-started.md`; worker admission, host PHP
extensions, cgroup requirements, resource ceilings, credentials, private IPC storage
and health evidence come from the Worker deployment section of
`docs-book/part-1-operator-guide/commands/README.md`. Check commands against current
source before execution. `README.md` owns the introduction and verification caveat.
No new vocabulary is introduced into `CONCEPTS.md`.

### Sequencing and Open Questions

Review this plan before execution. Establish isolation and prerequisites before
starting dependencies; complete the source guide in order, then record and review
the evidence. No product decision blocks starting. Runtime compatibility, host
`posix` availability and successful worker admission remain execution-time unknowns.
The lead has observed that Docker cannot bind the tool checkout and host PHP lacks
`posix`; the adaptations above preserve isolation while their coverage limits remain
explicit. The initial browser attempt established the HTTPS login blocker:
`SpaController` derives its API origin from the request; without proxy trust it
renders HTTP, and the development template removes the custom port. Review the
added repair scope before implementation, apply U3, then resume the blocked U1
browser checks before finalizing U2.

## Implementation Units

### U1. Establish and exercise the disposable installation

Covers R1–R6 and R9; follows KTD1–KTD3 and KTD5. Own temporary checkout, configuration, generated
media, resources and logs outside tracked files. Start from the repaired source,
check host/controller prerequisites and resource capacity, prepare isolated storage
and credentials, and follow the installation guide through browser login, worker
startup and scanning. Use the browser automation skill for interactive evidence. Own the optional
metadata-only `ui/web/yarn.lock` correction; verify that its diff changes no
dependency resolution, then run pinned immutable installation, type-check and build.

Verify: dependency health; Composer runtime autoloader; successful migration and
OAuth setup; frontend build and browser asset loading; administrator login;
admitted worker pair and operational health; sample scan completion and visible
library contents. Record which outcomes are blocked if a prerequisite or application
failure prevents later checks. Clean up only this run's resources.

### U2. Correct the guide and record the checkpoint

Covers R7–R8 and R11; depends on U1 and U3. Own `README.md`,
`docs-book/part-1-operator-guide/getting-started.md`,
`docs-book/part-1-operator-guide/configuration.md` and `ROADMAP.md`. Correct linked
operator references only where evidence establishes an error needed to follow the
installation; keep ownership explicit if delegated. Preserve the expanded README's
purpose and earlier historical checkpoints. Record concrete outcomes and outstanding
work with enough evidence to reproduce a failure without retaining credentials.

Verify: command and link accuracy, prose preservation audits, whitespace checks,
phase-boundary simplification review and final independent review. No new application
test suite is required for documentation corrections. Record the relevant runtime
check counts and static gates, then create local commits on the plan branch.

### U3. Repair development proxy origin forwarding

Covers R10–R12; follows KTD6–KTD7. Own
`docker/dev/nginx.conf.template`,
`tests/Unit/Shared/Infrastructure/Http/ProxyTrustTest.php` and
`scripts/test-websocket-proxy-logs.sh`. The image uses this template; avoid changes
to the unused development configuration or production proxy. Preserve authority
and suppress client-supplied forwarded port in the shared server header block and
both explicit location blocks, which override inherited proxy headers.

Verify a failing custom-port regression before the change. Extend the existing
trusted URL/DPoP cases for HTTPS on a custom port with forwarded-port absent, retain
default HTTPS-port and untrusted-peer controls. Extend the existing disposable
nginx drill with a loopback header-echo upstream for the active template; exercise
ordinary, WebSocket and inherited media routes with a custom authority and spoofed
forwarded host, scheme and port. Assert the preserved authority, HTTPS scheme and
absent forwarded-port header; retain the existing refused-upstream logging checks.
Run the focused PHPUnit test and nginx drill in their CI images. After U2's proxy
trust instructions are applied to the disposable installation, verify the SPA's API
origin equals the browser origin and repeat administrator login and authenticated
requests. No application URL workaround or broader proxy redesign is required.

## Verification Contract

The acceptance evidence is the observed runtime outcomes in U1 and the focused
proxy regressions in U3, including a real
browser session. A successful image build or controller status cannot substitute
for them. Keep bounded logs outside the tracked tree and identify the source
revision and disposable configuration adjustments in the checkpoint. Use the
repository's applicable documentation checks, `prose-fix` audit script for each
rewritten file, local link/anchor checks and `git diff --check`. Run `ce-simplify-code`
at the unit boundary and `ce-code-review` before the final local commit; apply
justified findings within the authorized scope.

## Definition of Done

The four previously unverified outcomes have either concrete passing evidence or
an explicit blocker and impact recorded in `ROADMAP.md`. Documentation reflects the
observed state without erasing history. The authorized proxy repair passes its
regression checks and browser verification, or its remaining blocker is explicit. Reviews and preservation/static gates pass,
run-owned resources are accounted for, and the lead creates the local commit. An
incomplete bootstrap remains open acceptance work even when this verification
attempt and its documentation are delivered.
