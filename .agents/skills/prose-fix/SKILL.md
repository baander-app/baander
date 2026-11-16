---
name: prose-fix
description: Rewrite or review technical documentation in the Baander project's house voice while preserving facts, requirements, code, and structure, and present features in website page openings with every claim sourced. Use for requests to fix, polish, humanize, or rate prose in READMEs, manuals, designs, specifications, policies, and runbooks, or to introduce a feature in the Jobs register. Not for fiction or channel copy such as social posts and launch announcements.
---

# Prose Fix

Repair technical prose at the paragraph level. Preserve the author's meaning and
the project's decisions; improve the explanation, not the system being explained.
Keep effective passages rather than rewriting them for novelty.

## Scope and context

For a rewrite request, edit the requested files in the current workspace.
For a review or rating request, report findings without editing. If the user supplies
only text, return the revised text or review in the response. A draft request, which
writes new copy instead of rewriting existing prose, is available only in the Jobs
register: draft from the feature's documentation and the sources the request names,
then verify the draft as "Verify preservation" describes.

Read applicable `AGENTS.md` instructions and inspect working-tree changes before
editing. Preserve existing user edits. Read each target document in full, in chunks
if necessary, and capture its current contents as an audit baseline before changing
it. Use a temporary copy of the working file, not `HEAD`, so the comparison includes
only this rewrite. Keep baseline files outside the tracked tree.

In Baander, read [the project house style](references/project-house-style.md) for
document assignments, documentation sources, and mechanical conventions. In another
project, use that project's rules; do not import Baander conventions. User instructions
take precedence over this skill and its references.

## Choose the register

Use the project's document mapping unless the user specifies another voice. For
unmapped documents, choose by purpose:

| Register | Purpose | Reference |
|---|---|---|
| Chen | Guides, tutorials, troubleshooting, reviews, postmortems: start with the situation and explain the mechanism in execution order. | [chen.md](references/chen.md) |
| Knuth | Runbooks, bootstrap procedures, ceremonies, drills: make prerequisites, quantities, step order, and guarantees precise. | [knuth.md](references/knuth.md) |
| Stroustrup | Designs, specifications, format references, policies: name the mechanism, define terms, and connect rules to their rationale. | [stroustrup.md](references/stroustrup.md) |
| Jobs | Feature presentation in a page opening: start from the reader's moment, turn specifications into what the reader gets, and source every claim. Only where the house style maps it or the request names it; never chosen by purpose alone. | [jobs.md](references/jobs.md) |

Read only the register references needed for the task. Mixed documents may use
different registers by section. Treat these sheets as guidance for explanatory
technique, not a demand to insert humor, personal anecdotes, admissions of ignorance,
new procedures, or quotations. Their examples illustrate voice; they do not establish
current project facts. Never invent details to reproduce an example's effect.

## Rewrite

- Rebuild weak paragraphs around their concrete subject and causal sequence. Word
  substitutions alone rarely repair an explanation. Match the extent of the rewrite
  to the request; leave prose that already works.
- Preserve facts, numbers, names, paths, commands, hashes, links, cross-references,
  inline code, and the force and scope of requirements. Preserve conditions,
  exceptions, negation, and uncertainty. A description such as "does not touch" must
  not become a guarantee such as "never touches".
- Keep headings, section numbers, anchors, code blocks and their contents, table
  rows, and historical entries unchanged unless the requested work includes them.
  Preserve file encoding and line endings. Reflow prose only where useful.
- Keep charter statements and quoted rules verbatim. If a factual error or policy
  conflict needs a content decision, flag it separately rather than silently
  resolving it as a style edit.
- Remove empty introductions, unsupported praise, vague hedges, repetitive
  summaries, and decorative synonym lists. Prefer concrete mechanisms and specific
  uncertainty. Do not apply a blind word blacklist or delete deliberate em-dashes.
  The Jobs register follows this rule too: its enthusiasm comes from the cited fact
  it chooses to lead with, never from an added adjective.
- Retain strong, accurate lines. Note notable retained lines briefly in the review
  or final response; a separate changelog is unnecessary unless the document uses one.

## Present a feature

These rules govern every rewrite and draft in the Jobs register, alongside the rules
above.

- Support every claim the copy adds, or keeps in the opening, with shipped code, a
  maintained document, or a recorded measurement. A claim that a feature is available
  cites maintained documentation (`README.md`, the operator guide or the developer
  guide), with code as corroboration. Code alone does not show availability, because
  groundwork for planned features ships too. Other site copy is not a source.
- Restate a specification as something the reader can picture only when the figure
  can be computed from a cited source fact. Record the source, any assumption the
  figure needs, and the arithmetic in the claim ledger.
- Present planned or in-development features as planned. Never describe them as
  available.
- Name no competing product. The competitor table in `STRATEGY.md` is positioning,
  not a source for copy. Measured comparisons stay on pages that report the
  measurement.
- Keep a qualifier beside the claim it qualifies when the claim is false without it.
  Other limits may move out of the opening into a labeled section or disclosure on
  the same page, at their original strength. Never drop a limit.
- Give a draft the documented limits of the features it leads with, placed by the
  rule above.

## Verify preservation

Run the bundled audit for each rewritten file, resolving the script path relative
to this skill directory rather than assuming the shell is in it:

```text
php "<skill-dir>/scripts/audit_rewrite.php" "<baseline.md>" "<edited.md>"
```

Use PHP 8.5 or newer. The script
reports inline-code token and number multisets, normative-word counts, selected
structural lines, and section-sign counts. It returns zero even when differences
exist: read the output, not just the exit status or final delta count.

Trace every reported change to the diff. Revert unintended changes; explain any
intentional, meaning-preserving differences, such as expanding "can't" to "cannot"
or updating document version metadata. Blank-line changes can also trigger the
structural report and need review rather than automatic rejection.

The audit does not prove semantic equivalence or fully check code-block contents,
names, links, paths, or reference targets. Inspect the diff and compare protected
content directly. Equal word counts can hide a moved negation or a stronger rule.
If PHP is unavailable, perform these comparisons manually and disclose that the
script was not run.

In the Jobs register, also write a claim ledger: each claim the copy adds or keeps
in the opening, with its source (a path and line, a manual page, or a measurement
record), and for each translated figure its assumption and arithmetic. Every number
and code token the audit reports as gained must appear in the ledger. The audit
cannot see a new claim that carries no number, so check each sentence of the opening
against the ledger. A draft has no baseline to audit; check it against the ledger
alone.

Re-read the result as a reader: the explanation should be clearer, every claim
should retain its original strength, and no paragraph should merely repeat its
predecessor. After any further edits, repeat the affected checks. Run
`git diff --check` for repository edits and any documentation checks required by the
repository.

## Deliver

Follow existing document version and history conventions where present. Where a
document requires a version update or history entry for prose changes, follow that
convention and preserve historical entries. Do not invent versioning for unversioned
files, bump software release versions, or refresh unrelated companion documents.
Account for metadata changes in the audit.

Leave the completed edits in the workspace. Commit, push, publish, or update remote
files only when the user's request or standing authorization includes that action,
using the current environment's tools and repository instructions. This skill does
not require a GitHub MCP connection or session-specific output mirrors.

Report the files changed, the chosen register where useful, the verification result,
and any unresolved content questions. For Jobs-register work, report the claim
ledger, and put it in the commit message of the change it covers so the evidence
stays tracked. For review-only work, give concrete findings
with file and line references or short quotations, identify strong passages worth
keeping, and recommend a register. Review chapters individually when the scope
warrants it; do not turn a rating request into an edit.
