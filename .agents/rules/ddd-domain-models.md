# Domain models

New aggregate roots use a mutable state object to keep factory and reconstitution
fields aligned. A matching repository contract is useful evidence of an aggregate,
not a complete definition: inspect nested feature folders and consistency boundaries.
Models without repositories may be child entities, value types, or simple models.

## Aggregates and state

Use a final aggregate with a private constructor accepting its `{Name}State`.
The public creation factory validates invariants and supplies new identity and
creation data. Preserve semantic factory names such as `register()`, `issue()`,
or `createByOperator()`; a literal `create()` method is not required.
`reconstitute(State)` restores persisted state through the repository.

The state class is final and mutable, with public fields for persisted aggregate
state. Preserve `readonly` identity/creation fields when mutations never reassign
them. The aggregate's mutations update state and relevant timestamps and enforce
its invariants. Keep derived data consistent with its source rather than creating
independently mutable duplicates. Use getters for ordinary clients; `getState()`
is a persistence/internal API, not the HTTP or serialized payload contract.

This project's aggregate convention uses a non-readonly class. That is a local
convention, not a PHP claim that readonly properties make nested objects immutable.
Keep ORM mappings and persistence adapters out of domain models.

Existing positional aggregates are accepted pending deliberate migration. Playlist,
MediaActivity, Recommendation, Notification, and NotificationPreference are current
examples. Preserve their behavior and report applicable migration work separately;
do not require a state rewrite as a prerequisite for unrelated changes.

## Value types and reconstitution

Use immutable value types for domain concepts with equality and validation semantics.
A public constructor and/or a named factory such as `fromString()` can fit the type.
Do not impose string factories or aggregate factories on every simple model.
Repositories use reconstitution for persistence; tests may use it to exercise
rehydration. Search all callers before changing that signature.

Reference shapes: `src/Catalog/Domain/Model/Album.php` and `AlbumState.php`,
`src/Auth/Domain/Model/User.php` and `UserState.php`, and
`src/Shared/Domain/Model/Uuid.php`. Discover their current paths and full fields;
examples are not a complete field or aggregate inventory.
