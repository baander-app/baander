# House style: Baander

These rules apply to prose-fix tasks in Baander, subject to the user's instructions
and applicable repository instructions. They take precedence over the general author
registers. The register sheets describe explanatory technique; this file maps that
technique to the repository's documents.

## Register assignments

Paths below are relative to the Baander repository root. These are defaults for this
skill; choose by section when a document mixes explanation, reference, and procedure.

| Register | Documents and sections |
|---|---|
| Chen | `README.md`; introductions and task explanations in `docs-book/part-1-operator-guide/`; tutorials, troubleshooting, and development guides in `docs-book/part-2-developer-guide/` and `dev-docs/` |
| Knuth | Ordered deployment, upgrade, recovery, and key-management procedures, including procedural sections of `docs-book/part-1-operator-guide/getting-started.md`, `upgrading.md`, and `security.md` |
| Stroustrup | Architecture, bounded-context descriptions, API and configuration references, coding conventions, and designs in `docs/plans/` and `STRATEGY.md` |
| Mixed | CLI pages in `docs-book/part-1-operator-guide/commands/`: Stroustrup for arguments, options, and behavior; Knuth for ordered procedures; Chen for examples and troubleshooting. Contribution guides: Stroustrup for rules and Chen for workflow explanations. |

A user-specified voice overrides these defaults.

## Documentation sources and boundaries

- `docs-book/README.md` separates the operator and developer audiences. Keep operator
  instructions focused on running an instance; explain implementation details where
  the reader needs them to understand or develop the system.
- `docs-book/part-2-developer-guide/contributing.md` records documentation maintenance
  rules. It names `.agents/rules/` as the authoritative coding conventions and
  describes regeneration of CLI documentation. Read the relevant source when editing
  derived guidance; flag disagreements rather than resolving them through rewording.
- `docs-book/` and `dev-docs/` coexist. `AGENTS.md` points agents to the canonical
  rules and repository skills. Editing one document does not authorize synchronizing the
  others or changing application configuration, commands, API schemas, or code.
- Preserve the distinction between proposed behavior in `docs/plans/` and implemented
  behavior in guides. Keep plan frontmatter, dates, status, acceptance criteria,
  checkboxes, non-goals, and recorded decisions intact unless the requested work
  includes them. A prose rewrite is not evidence that a plan has been implemented.
- Preserve technical names and boundaries: bounded contexts, the Shared kernel,
  Domain/Application/Infrastructure/Interface layers, commands, events, and queries
  are not interchangeable synonyms. Keep existing identifiers and product spelling
  (`Bånder` or `Baander`) as written unless naming changes are requested.
- Preserve claims about authentication, authorization, streaming, monitoring, and
  external services at their original strength. Do not add guarantees, restrictions,
  or project policies to make an explanation sound more decisive.

## Mechanical conventions

Preserve Markdown headings and anchors, relative links, tables, code blocks, command
flags, environment-variable names, API routes, class names, numeric limits, and units.
Keep the document's existing punctuation and quotation conventions; deliberate
em-dashes are not defects. Do not impose section-sign references or document-version
headers on files that do not use them.

For procedures, retain prerequisites, command order, conditions, warnings, and the
scope of each operation. Rewriting a command's explanation does not authorize running
it. For references, retain defaults and distinctions between required and optional
values. Keep quoted rules and historical records verbatim.

## Preservation and delivery

Use the baseline, audit, and diff review described in `../SKILL.md`. The audit is a
report, not a pass/fail gate: account for every delta and inspect content it cannot
validate, including links, fenced code, frontmatter, and changes in requirement force.

Follow version/history conventions only where the target document has them. Do not
add revision metadata, change software release versions, or advance plan status for
a prose-only edit. Run `git diff --check` and applicable documentation checks. Leave
changes local unless the user has authorized committing or publishing.
