# State migration mapping checklist

Read the complete aggregate, repository mappings, entities, and all callers of its
factory and reconstitution method. Include nested feature models, test fixtures,
serializers, and cross-context callers; filename-matched tests are not enough.

Inventory constructor fields, body-assigned fields, collection defaults, extra
reconstitution arguments, derived properties, and mutation assignments. Distinguish
persisted source fields from values recomputed from those fields. Preserve optional
defaults and restoration behavior, especially post-construction collection setup.

Create a final mutable `{Name}State` beside the aggregate, with public fields and
readonly identity/creation fields that are never reassigned. Retain collection
PHPDoc element types. Do not place business behavior or a persistence dependency in
the state. It is an internal aggregate/repository API, not a public payload schema.

Change the private constructor to accept state; preserve semantic creation methods
and their validation. Build state with named arguments. Reconstitution consumes the
state and preserves every persisted value; creation still generates only genuinely
new identities. Delegate getters and mutations to state, updating relevant timestamps.
Compute derived values from their sources or preserve initialization consistently.

Map repository entities to state with every field named explicitly. Existing getters
may remain the synchronization interface; introducing `getState()` does not require
rewriting every repository helper. Preserve entity identity, nulls, collection order,
and immutable fields in both directions.

Update every changed reconstitution caller and relevant test fixtures. Use GitNexus
for semantic renames rather than find-and-replace. Search for direct aggregate field
access and serialization assumptions; moving private fields can affect reflection or
serialized caches even when ordinary getters are unchanged.

Verify creation invariants, semantic factories, mutation transitions, collection
behavior, and rehydration with representative null/default/identity values. Run
focused existing tests before broadening validation. When persisted behavior changes,
use a disposable database and examine actual mappings; do not assume this structural
migration authorizes a schema rewrite.

Current reference models already migrated: Catalog Album and Auth User. Positional
examples remain in Playlist, Activity MediaActivity, Recommendation Recommendation,
and Notification Notification/NotificationPreference. Discover current code rather
than treating this list as a complete or permanent backlog.
