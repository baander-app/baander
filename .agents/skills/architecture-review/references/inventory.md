# Context inventory

Identify the requested context directories from current source, including nested
feature folders. Do not use a hardcoded context table or infer missing layers are
errors. Shared has all four layers; utility contexts may intentionally have fewer.
Use `rg --files` for a bounded inventory after GitNexus discovery.

Classify current files by role: models/state/value types, repository contracts and
implementations, ports, commands/handlers/messages, controllers/console entrypoints,
request DTOs/resources, persistence mappings, and event definitions/consumers.
Notification's DTO/Handler layout and Auth's nested OAuth/Passkey/User folders count.
Report unknown roles rather than inventing a classification from a suffix.

Find aggregate candidates by repository contracts and domain consistency boundaries,
not a flat `Domain/Model/Name.php` glob. Resolve repository and port implementations
by fully qualified interfaces, including decorators and external adapters. A matching
short class name or one-line `implements` grep is insufficient.

Inventory controller routes from actual attributes, including class-level prefixes,
and note commands/ports consumed outside HTTP. Locate events from their contracts,
not only filenames containing `Event`. Determine dispatchers and listeners before
claiming events are unused; Symfony and Messenger wiring may be dynamic.

Documentation inputs should record:

- Context purpose, layers, and feature folders.
- Models/aggregate candidates, persisted state, and remaining migration patterns.
- Ports, repositories, resolved adapters, and synchronous/asynchronous entrypoints.
- Events and significant cross-context contracts, grouped by importing layer.
- Evidence paths and counts scoped to the inspected files.
- Known debt and unknown coverage, separately from current behavior.

Use `dev-docs/context-map.md` and `src/Context/README.md` as documentation evidence.
Do not assume a context table exists in an agent-instruction file. This inventory
can feed documentation work; it does not itself authorize rewriting documentation.
