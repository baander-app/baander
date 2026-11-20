---
title: Documentation readability - Plan
type: docs
date: 2026-11-20
artifact_contract: ce-unified-plan/v1
product_contract_source: user-request
execution: knowledge-work
---

# Documentation readability - Plan

## Goal Capsule

Make the operator and developer guides easier to use by separating tasks,
explanations and reference inventories, then rewriting the selected pages with
`prose-fix`. Preserve the technical content readers need to act safely.

The user authorizes the documentation changes. Repository instructions govern
preservation, verification and local commits; publication is outside this work.
The lead integrates the units, records delivery in `ROADMAP.md` and completes the
final checks. Stop a particular deletion when its factual basis cannot be
established; continue the independent edits and report the unresolved content.

## Product Contract

The user reports that some pages are very long while others are terse or verbose.
Length identifies candidates for inspection; it does not establish that their
content is unnecessary. A reference inventory can be long and still useful.

The initial audit found 250 Markdown pages and approximately 254,850 words in
`docs-book/`. Its largest candidates include operator security (4,838 words),
transcoding (4,837), authentication and limits (4,801), user management (4,101),
external APIs (3,560) and upgrading (3,429). Developer candidates include media
and playback testing (12,099), Auth (11,645), Catalog (9,020), API authentication
(5,951) and workers and messaging testing (4,818). These are audit observations,
not target lengths. Reproduce counts from the working Markdown files before
editing and record the counting method with the delivery evidence.

### Requirements

- R1. Give each selected page a clear audience and purpose. Split it when readers
  need distinct tasks or reference material, and provide enough explanation in
  terse sections to make prerequisites and outcomes understandable.
- R2. Preserve commands, examples, tables, defaults, limits, warnings, exceptions,
  requirements and historical evidence. A split moves content; it does not
  silently discard it. Rewrite prose without strengthening technical claims.
- R3. Remove repetition when a maintained destination covers the same meaning.
  Remove obsolete claims only after checking tracked source and relevant history;
  record the evidence and replacement or reason in the delivery entry.
- R4. Keep navigation and internal links usable after every move. Readers entering
  through an existing page must still find its former topics.
- R5. Preserve the distinction between available features, planned groundwork and
  historical results. Keep OAuth authorization-code and device-code groundwork,
  movie and concert material, and operational safety guidance.
- R6. Deliver local documentation commits with measured verification recorded in
  `ROADMAP.md`. Do not change application behavior, `ui/rn`, or Essentia.

### Acceptance examples

- An operator seeking rate-limit configuration reaches a task-focused page from
  the existing authentication page and finds the same configuration names,
  defaults and exceptions.
- A developer seeking an Auth inventory reaches it from the context overview
  without reading the full protocol explanation. Planned flows remain labeled.
- A developer seeking media test instructions finds commands and prerequisites
  separately from the test inventory and dated qualification results.

## Planning Contract

Keep a short overview at each existing entry path and move complete topics into
sibling pages. Use the existing directory hierarchy; do not add a second guide
tree. Choose filenames after reading the actual section boundaries. Every moved
section needs a recorded old path/heading and new path/heading so the lead can
repair inbound links and preserve useful old anchors with short pointers.

Use `prose-fix` with its house-style mapping: explanatory guides use Chen,
procedures use Knuth, and context/design references use Stroustrup. Read every
target in full. Preserve the lead's captured working-file baseline outside the
tracked tree for comparisons; it is execution scratch, not durable evidence.
Effective prose stays. No arbitrary page-length quota applies.

Keep material that supports the page's task or establishes a necessary limit.
Move detailed inventories, protocol internals and dated qualifications to named
references when they interrupt that task. Delete only verified obsolete claims,
empty repetition, or duplicated explanations replaced by an explicit link. Read
the corresponding tracked code, maintained documentation and relevant history
before making a factual correction. A `var/` receipt alone cannot support a
durable claim: find tracked evidence or retain the historical claim with an
explicitly bounded evidential status.

U1–U3 can run independently with non-overlapping ownership. Agree on destination
paths before moving material across units. Workers return their move map,
deletion evidence, baseline audit results and unresolved claims; they do not
stage or commit. The lead owns shared indexes, `ROADMAP.md`, integration and local
commits on a branch named for this plan. Run `ce-simplify-code` at phase boundaries
as an applicability check for this documentation work and `ce-code-review` before
delivery; apply justified findings. This plan passes `ce-doc-review` before edits
begin. No new domain concept is intended; update `CONCEPTS.md` only if the work
actually introduces one.

## Implementation Units

### U1. Separate operator tasks from technical reference

Own the bodies of `docs-book/part-1-operator-guide/maintenance/security.md`,
`configuration/authentication-and-limits.md`, `administration/user-management.md`,
`media/transcoding.md`, and sibling pages created from them. Inspect
`media/external-apis.md` and `maintenance/upgrading.md`; split or rewrite
them only where their content shows the same problem. Verify their actual paths
before editing.

Separate authentication configuration from rate-limit reference, security tasks
from explanatory details, user administration from identity internals, and
transcoding operation from detailed tuning/reference material. Preserve command
order, warning placement and capacity defined by transcode slots. Coordinate any
developer-reference destination with U2 or U3 before moving content across their
ownership. Covers R1–R5. Return a move map and evidence for each substantive
deletion; verify the operator acceptance example against the resulting pages.

### U2. Separate developer overviews, protocols and inventories

Own `docs-book/part-2-developer-guide/contexts/auth.md`, `contexts/catalog.md`,
`development/api-authentication.md`, and sibling pages created from them.

Keep concise context overviews that explain responsibilities and direct readers
to separate implementation inventories and detailed flows. Consolidate repeated
API-authentication explanations at a clear canonical destination without losing
context-specific constraints. Inspect source and history before removing an
apparently unused flow or identifier. Preserve the distinction between shipped
behavior and authorization-code/device-code groundwork. Covers R1–R5. Verify the
Auth acceptance example, all moved inventories and protocol qualifiers.

### U3. Separate test instructions from coverage and qualification history

Own `docs-book/part-2-developer-guide/testing/media-and-playback.md`,
`testing/workers-and-messaging.md`, `testing/frontend-and-browser.md`, and sibling
pages created from them. Coordinate final filenames with the lead.

Make commands, prerequisites and normal test selection easy to find. Move long
test inventories and dated qualification evidence into linked reference pages.
Inspect workers/messaging and browser testing for the same mixed-purpose pattern;
retain sections that already work. Preserve exact commands and the dates, counts,
conditions and limitations attached to historical results. Do not treat earlier
results as checks performed in this task. Covers R1–R5. Verify the media-test
acceptance example and audit all moved evidence and command blocks.

### U4. Integrate navigation and record verified delivery

The lead owns affected `README.md` indexes in `docs-book/`, shared navigation,
inbound-link repair outside U1–U3, this plan and `ROADMAP.md`. This unit depends on
the move maps from the other units.

Give rewritten entry pages a concise purpose and links to their new destinations.
Check every new page is reachable from a maintained index. Repair references to
moved headings, including references outside `docs-book/`, and retain useful old
entry anchors as pointers where needed. Record selected pages, resulting splits,
justified removals, unresolved evidence and exact checks in `ROADMAP.md`. Covers
R4 and R6; integrate the other units' preservation evidence.

## Verification Contract

For every rewritten file, run the `prose-fix` audit using PHP 8.5 or newer and its
working-file baseline. Inspect every reported delta; the script's zero exit code
does not establish preservation. For splits, compare the original against the
combined destinations as well as checking each destination. Manually compare
fenced code, tables, links, numeric defaults, warnings and requirement strength.

Run `git diff --check` and `make website-build`, the public-site and searchable
manual build that validates cross-page anchors and Pagefind (`Makefile`,
`scripts/build-website.php`, `packages/baander-dev-website/src/WebsiteBuilder.php`).
Check local Markdown file targets and heading fragments across the affected
documentation and inbound references; record how many links
and anchors were checked, and investigate every new failure. Do not change the
documentation generator merely to make this rewrite pass.

Verify generated navigation and representative moved-page anchors in the public
site output. Record build limitations separately from link failures. No backend
test suite is required for prose-only edits; any generator change would require
a separately justified scope and its own tests.

Read documented operational commands as protected content; do not execute them
as part of verifying a prose rewrite.

## Definition of Done

Each selected long page has a documented keep, split or rewrite decision based
on its contents. The three acceptance examples work, new pages are indexed, and
moved content remains reachable. Deletions have tracked evidence, protected
content comparisons are accounted for, and current checks are distinguished from
historical results. The documentation build and link/anchor checks pass, or an
existing unrelated limitation is documented with its observed evidence. Final
review findings are resolved and `ROADMAP.md` records the delivered scope and
verification counts. Changes are committed locally; nothing is pushed.
