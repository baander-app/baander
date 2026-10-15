# Qualify Messenger delivery without Swoole

## Scope

Qualify the roadmap's Messenger/Redis fallback in the CI image with the Swoole
extension unavailable. Use the existing disposable Compose coordinator and its
isolated PostgreSQL and Redis services. This qualification covers console boot,
Messenger routing, real Redis consumption, retry and dead-letter handling, and
registration of handlers restricted by transport. It does not qualify the web
server or replace its native Swoole runtime.

## Implementation

- Add an explicit opt-in coordinator mode, `BAANDER_TEST_DISABLE_SWOOLE=1`.
  Before discovery or application boot, disable only the Swoole loader inside
  each disposable app container. Preserve the image's main PHP configuration and
  all other extensions. Reject an unexpected loader or extension set. Record PHP
  version and extension evidence in the run artifacts.
- Apply the mode to discovery and every lane. PHP children must inherit the same
  configuration. Add an acceptance test that checks absence in the test worker
  and a nested PHP process; execute it only in the dedicated qualification.
- Reuse `MessengerJsonDeliveryTest` and `SwooleFallbackHandlerRegistrationTest`
  to prove actual delivery and transport registration. Keep existing strict
  result completeness, timeout, log retention and cleanup behavior.
- Add a blocking CI qualification and document its local command. Keep ordinary
  backend runs unchanged and retain the Composer extension requirement.

## Runtime finding

The first qualification passed the absence guard and handler registration, but
console cache clearing failed when the container mutex requested a coroutine ID.
Bypassing mutex initialization exposed the same native call in compiled service
pools. Both callers already support the synchronous context ID `-1`.

Make the bundle's Swoole bridge return `-1` for coroutine identity queries when
its extension is absent. Keep native thread/coroutine identities unchanged when
it is loaded. Retain the kernel's existing initialization and proxy paths. Add
absent-runtime assertions for both identity methods; console cache clearing,
migrations and Redis delivery provide the boot regression. Rerun native pool
release and kernel/generated-container tests with Swoole enabled.

## Verification and delivery

Review this plan independently before implementation. Add focused runner
regressions for opt-in behavior and rejected runtime configurations. Run the
qualification in the current CI image, verify nested-process extension absence,
and confirm disposable resources are removed. Run the affected tests normally
with Swoole enabled, runner regressions and the applicable static gates.

Simplify and independently review the changes, apply justified findings, update
the testing guide and ROADMAP.md with actual counts and timings, and apply
prose-fix. Reconcile the completed ParaTest backlog checkbox with its existing
delivery checkpoint. Commit locally; publishing requires a separate request.

The compound-engineering skills are unavailable in this environment. Use the
manual plan, independent review, implementation, simplification and final review
workflow already documented in the roadmap.
