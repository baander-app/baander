---
name: prose-fix
description: Rewrite or review technical documentation in the Baander project's house voice while preserving facts, requirements, code, and structure. Use for requests to fix, polish, humanize, or rate prose in READMEs, manuals, designs, specifications, policies, and runbooks. Not for fiction or marketing copy.
---

# Prose Fix

Repair technical prose at the paragraph level. Preserve the author's meaning and
the project's decisions; improve the explanation, not the system being explained.
Keep effective passages rather than rewriting them for novelty.

## Scope and context

For a rewrite request, edit the requested files in the current workspace.
For a review or rating request, report findings without editing. If the user supplies
only text, return the revised text or review in the response.

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
- Retain strong, accurate lines. Note notable retained lines briefly in the review
  or final response; a separate changelog is unnecessary unless the document uses one.

## Verify preservation

Run the bundled audit for each rewritten file, resolving the script path relative
to this skill directory rather than assuming the shell is in it:

```text
python "<skill-dir>/scripts/audit_rewrite.py" "<baseline.md>" "<edited.md>"
```

Use the available Python interpreter (`python`, `python3`, or `py`). The script
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
If Python is unavailable, perform these comparisons manually and disclose that the
script was not run.

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
and any unresolved content questions. For review-only work, give concrete findings
with file and line references or short quotations, identify strong passages worth
keeping, and recommend a register. Review chapters individually when the scope
warrants it; do not turn a rating request into an edit.
