# Remove completed plans

Delete completed or superseded plans from `docs/plans/`, as requested. Retain
plans supporting open or explicitly deferred roadmap work. Use ROADMAP.md's
verification checkpoints to classify plans; a date alone is not completion.

Keep delivered scope and verification in ROADMAP.md. Replace links to deleted
plans with existing delivery checkpoints or plain historical descriptions, and
repair references in guides and solution records. Keep `docs-book/` free of
plan references; explain current behavior directly instead of linking to plans. Do not delete requirements
that exist only in an unfinished plan. This cleanup plan is also removed when its
work is complete; Git history retains the planning record.

Independently review the classification before deletion, inspect every affected
reference, apply prose-fix, check that no tracked reference points to a deleted
plan, and run `git diff --check`. Record the deletion and retained-plan counts in
ROADMAP.md. This is documentation-only work; application suites need no rerun.
Review the final diff and commit locally. Publishing remains a separate action.
