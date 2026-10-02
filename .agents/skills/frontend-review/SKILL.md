---
name: frontend-review
description: Review a Baander web feature for React lifecycle correctness, API integration, state behavior, design compliance, and meaningful tests. Produces findings without applying fixes.
---

# Frontend review

Review the feature or diff the user names under `ui/web/`. If scope is missing,
infer it from the requested change or ask for the feature. A quick review checks
structure and available automated evidence; a full review also traces behavior.
Neither mode applies fixes. Save a report only when requested or when the user
has established a report destination.

Read [frontend conventions](../../rules/frontend.md) and the relevant sections
of `ui/DESIGN.md`. Inspect `package.json`, `vite.config.ts`, ESLint/TypeScript
configuration, and nearby tests before assuming what is enforced. Use GitNexus
for unfamiliar execution flows when available, then targeted file reads.

## Review scope

- Trace component/hook ownership, effects, subscriptions, async races, cleanup,
  StrictMode replay, and failure/loading states. Explain the user-visible result
  of a defect rather than reporting a missing hook by itself.
- Inspect actual props/state types and Zustand selector stability. Interfaces,
  aliases, native-prop intersections, and components with no props are valid.
  File length and absent memoization are investigation hints, not findings.
- Follow API calls through generated Orval contracts or shared Axios, backend
  routes/resources, validation, and authorization. Check response envelopes,
  cache invalidation, error paths, and ownership boundaries. Resolve relative
  imports instead of assuming a fixed number of `../` segments crosses a feature.
- Check styled-components, shared primitives, tokens, keyboard behavior, focus,
  accessibility, and product interaction requirements. Existing deviations from
  `ui/DESIGN.md` are not permission to repeat them. Keep native fetch exceptions
  limited to the browser/worker or asset contracts in the frontend rules.
- Read tests under both source and `tests/`. Assess what assertions prove;
  distinguish characterization of a known defect from the intended behavior.
  Use browser automation when the review requires browser-visible evidence.

For substantial independent areas, delegate bounded architecture/lifecycle,
integration, or test/browser review to the available collaboration tools. Start
with at most three workers and leave integration to the lead. Do not require
delegation for a small feature or make workers repeat the same file inspection.

## Verification and result

Run the relevant existing checks from `ui/web/`: `yarn typecheck`, `yarn lint`,
`yarn test`, or a targeted Vitest invocation. Use Playwright only against an
explicitly routed disposable application; never infer production access from
the configured domain. Follow the repository test-domain rules.

Separate findings into errors (incorrect behavior), warnings (demonstrated risk
or maintenance cost), and information (coverage or evidence limits). Each finding
needs a file/line, trigger, observed or inferred behavior, supporting evidence,
and a bounded remedy. Mark static hypotheses as such; do not claim a reproduction
or passing check that did not run. Deduplicate overlapping findings and state the
scope, checks, and remaining uncertainty. Report findings in the conversation;
apply fixes only if the user's request authorizes that follow-up.
