# Context analysis and documentation

For a requested context, validate its directory under `src/` and inventory PHP
files recursively within its actual layers. A context may have fewer than four
layers or feature folders such as Auth's OAuth, Passkey, and Totp directories;
neither proves incomplete architecture by itself.

Read relevant domain models/state objects, repositories, ports, handlers,
controllers/resources, service aliases, and tests. Use matching repository names
as aggregate candidates, then verify their ownership and persistence contract.
Resolve wiring from `config/services.yaml`; a repository outside
`Infrastructure/Doctrine/Repository/` is a convention finding, not evidence that
it does not work. Include commands, listeners, and ports outside conventional
filename patterns when their declarations or wiring establish their role.

A read-only analysis should give the context's responsibility, present layers,
important models, ports, commands/handlers, repository wiring, routes, events,
and dependencies, with file references. Report heuristics and uncertainty.
Imports suggest dependencies but do not establish runtime execution or an
anti-corruption layer. Confirm those through implementations and callers.

For a context README, use [the context template](../assets/context-readme.md)
only when creating a new document or matching its existing generated format.
Fill every token from source and preserve handwritten notes in an existing file.
Do not overwrite a hand-written README to force it into the template.

For architecture or context maps, derive relationships from source plus wiring
and trace event producers and consumers separately. Live events are captured
in the outbox; notification/admin projection listeners run on the private
`NotificationReplayDispatcher`. Receipts, projection writes, and channel intents
share replay transactions. Do not describe the bridge as a subscriber on every
live event or imply all producers already have atomic capture. Read the actual
transaction boundaries and delivery code before documenting guarantees.

Use Mermaid when it clarifies an evidenced relationship. Label direct imports,
ports/adapters, shared kernel use, and event-driven integration accurately.
Shared imports still obey the layer rules; importing Shared Infrastructure into
a Domain model is not excused by calling Shared a kernel.

Existing maps and per-context READMEs have generated sections followed by notes.
Keep those boundaries. Check related documentation-book guides for contradictions
without expanding a narrow request into a repository-wide rewrite. Architecture
review may use the repository's focused architecture skill; documentation
maintenance reports findings and does not silently repair source code.
