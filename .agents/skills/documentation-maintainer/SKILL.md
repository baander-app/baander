---
name: documentation-maintainer
description: Analyze Baander contexts and maintain requested context docs, architecture maps, PhpStorm scope guidance, or CLI reference pages from real source. Includes read-only drift checks; prose polishing belongs to prose-fix.
---

# Documentation maintenance

Work within the destination and scope requested by the user. An analysis or
drift check is read-only; selecting a document does not authorize regeneration.
For an update, inspect its current contents and handwritten sections before
editing. Preserve unrelated content, established headings, examples, and links.
Use the repository's `prose-fix` skill for the prose and preservation audit.

Read the relevant mode reference, rather than loading every mode:

| Request | Reference |
|---------|-----------|
| Analyze a context; update its README or architecture/context map | [Context analysis and docs](references/contexts.md) |
| Refresh or check PhpStorm scopes/navigation | [PhpStorm guidance](references/phpstorm.md) |
| Update or check CLI command pages/index | [CLI reference maintenance](references/commands.md) |

Derive facts from the actual code, configuration, and tests. Use GitNexus query
and context for unfamiliar flows; do not infer runtime behavior solely from
filenames, `use` statements, or a matching repository name. Rules are in
`.agents/rules/` and AGENTS.md. Document an inherited mismatch honestly rather
than rewriting application code to make the docs easier to generate.

Default existing destinations are `src/<Context>/README.md`,
`dev-docs/context-map.md`, `dev-docs/architecture.md`, `dev-docs/phpstorm.md`, and
`docs-book/part-1-operator-guide/commands/`. The documentation book also has
context guides; update the affected authoritative guide when requested and
avoid creating a second conflicting description. Full synchronization means
the explicitly requested destinations, not every Markdown file in the repo.

For check mode, report concrete drift with source evidence; do not change files
or add generation timestamps. For updates, preserve notes outside generated
sections and update metadata only under the document's existing convention.
Validate links, placeholder completion, and any command/option facts changed;
run the prose audit and `git diff --check`. Report changed destinations, evidence,
checks, and unresolved questions. Maintenance does not authorize issuing remote
comments, publishing docs, committing, or merging.
