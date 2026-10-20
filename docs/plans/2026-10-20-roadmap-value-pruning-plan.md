# Roadmap value pruning
Created: 2026-10-20

The user requested removal of roadmap paths that bring no return, after repeated
qualification and test work. This change makes `ROADMAP.md` a useful work selector
with a concise forward-looking roadmap. Important enduring facts belong in the
existing documentation; git history retains completed delivery narratives.

## Scope and decisions

- Edit `ROADMAP.md` and the relevant existing design, developer or operator
  documentation where important facts would otherwise be lost. Apply `prose-fix`
  to changed documentation. Leave application code, test suites, CI, skills and
  linked historical plans unchanged.
- Select work for a concrete defect, a requested feature with a current consumer
  or player call path, or a named release decision that existing evidence cannot
  answer. An unchecked item or an incomplete experimental path does not select work.
- Remove speculative expansion from the active backlog: broader store replay and
  asynchronous provenance, hypothetical API growth, and standing qualification
  beyond a concrete change or release need. Preserve delivered debugger features
  and their safety limits. These proposals can be reconsidered on a concrete need;
  they are not required foundations for ordinary feature delivery.
- Preserve regional deployment, constrained-host budgets, the accepted performance
  baseline, the 24-hour soak, actual-destination backup acceptance, codec/DSP release
  requirements, and other named first-release prerequisites. Keep mobile deferral.
- Remove completed sections and redundant checkpoint narratives from the roadmap.
  Git history retains dates, suite counts, failures and run narratives. Keep
  enduring operational limits, feature boundaries, safety requirements and useful
  verification guidance in their existing documentation destinations, adding only
  facts those documents lack. Do not create a large historical archive.
- Correct obsolete forward-looking wording using later delivery checkpoints. Retain
  acceptance still missing rather than treating local checks as release certification.

## Preservation destinations

- `relay/README.md`: retain the precise whole-host Registry acceptance budget
  currently recorded only in the roadmap; API, security, packaging, inventory and
  backup implementation guidance already lives in the Registry documentation.
- `docs-book/part-2-developer-guide/testing.md` and
  `dev-docs/worker-runtime-inventory.md`: replace the eight inbound roadmap
  checkpoint links with useful documentation references when their anchors disappear.
- Existing operator security, configuration, upgrading and backup guides, context
  documentation, `CONCEPTS.md`, and DSP documentation are the first destinations
  for enduring facts. Verify coverage before adding prose. Preserve reserved worker
  control capacity, autoscaling within execution pairs, encryption before upload,
  and the authorized scope of local database resets.

## Editing sequence

1. Inventory enduring facts in completed roadmap sections against relevant existing
   documentation. Record a compact preservation mapping in the completion report;
   migrate only missing useful facts before removing their roadmap source.
2. Tighten `Resume here` and `Delivery horizons` so speculative developer tooling,
   hypothetical scale and repeated qualification cannot become automatic next work.
   Remove obsolete open-work teasers from the current status summary.
3. Prune the active long-horizon developer-tooling and Registry growth directions.
   Keep genuine unfinished requested features and named release gates. Remove
   duplicate carryovers already delivered, including Registry backup implementation
   and packaged dependency inventory; retain destination and deployment acceptance.
4. Reconcile stale follow-up wording against later checkpoints, including native
   scrubbing, database reset, removed CLI policy, and push timestamp handling where
   the recorded delivery supports it. Repair links affected by section removal
   using useful existing documentation targets; do not preserve dead anchors.
5. Remove completed delivery sections and repetitive checkpoints once their
   enduring facts are covered by existing documentation. Keep a concise roadmap
   organized around current direction, real unfinished features, named release
   acceptance and explicit deferrals.
6. Add a concise delivery checkpoint recording this documentation cleanup and its
   checks. Do not select a replacement implementation task during this cleanup.

## Verification and completion

Review the complete diff against the preservation mapping, release prerequisites
and mobile scope. Check changed links and references to removed roadmap anchors,
run the prose-preservation audit for each rewritten document and
`git diff --check`, and record those results in `ROADMAP.md`. No product tests,
new test cases or qualification runs are justified by this documentation change.
Run the required document review before implementing this cleanup, then review and
simplify the final documentation diff before the local commit. Leave the work
unpublished; the user has already authorized the cleanup.
