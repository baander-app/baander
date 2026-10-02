# PhpStorm scope and navigation upkeep

The maintained guide is `dev-docs/phpstorm.md`. `check` compares the guide with
source without writing; `scopes` updates generated scopes; `sync` also updates
requested source-derived navigation counts or lists. These modes update the
guide, not personal `.idea/` files or installed IDE settings.

Inventory actual contexts and PHP files recursively under `src/`. Generate
context scopes with `file:src/<Context>//*` and layer scopes for Domain,
Application, Infrastructure, and Interface. Pattern scopes should match actual
repository contracts, handlers, ports, controllers, state objects, Doctrine
entities, resources, events, and migrations. Distinguish the glob's matches from
the count being reported; test exclusions do not make an incomplete glob correct.
For feature subfolders, confirm the proposed scope covers each intended layer.

Replace only the Custom Scopes section's generated region and requested counts
or lists in Navigation Tips. Preserve plugins, interpreter/path mappings,
bookmarks, live templates, plugin settings, quality-tool setup, and shortcuts.
Update generation metadata under the guide's existing convention when regenerating
scopes, not when merely checking drift.

Report missing/stale context scopes, mismatched counts, and nonmatching patterns
with source evidence. Do not claim the IDE accepts a pattern unless verified in
the IDE or authoritative syntax; textual generation alone proves no IDE behavior.
Existing examples with personal paths are examples, not agent workspace settings.
