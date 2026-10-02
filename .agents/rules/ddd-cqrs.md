# Commands and handlers

Commands carry immutable input data without business logic. Use final readonly
classes with meaningful use-case names. Either private fields with getters or
public readonly promoted fields fit existing contracts; do not add setters.

Discover commands and handlers recursively. The common layout is
`Application/Command/` and `Application/CommandHandler/`, including feature folders.
Notification also uses `Application/DTO/` and `Application/Handler/`; these are
existing layouts with the same contract requirements, not evidence of mutable
commands or missing registration.

Handlers orchestrate application/domain operations and depend on repositories and
ports rather than infrastructure implementations. Preserve Messenger registration
on the callable, including transport-specific attributes where present. A handler
need not return a domain model if its use case returns a scalar or no result.

Controllers dispatch through `MessageBusInterface` using the established result
stamps/response path; do not assume the bus directly returns a handler's value.
Check synchronous versus asynchronous routing before relying on nested dispatch.

For producers that must persist a domain write and outbox event atomically, use an
explicit producer transaction boundary. Related synchronous writes and token changes
belong to that boundary. Keep expensive hashing and pure validation outside when
this preserves behavior. Do not wrap the entire Messenger bus or relay batch in a
transaction. Failures must propagate so rollback and retry semantics remain visible.

Current examples: `src/Auth/Application/CommandHandler/User/RegisterUserHandler.php`,
`src/Playlist/Application/CommandHandler/CreatePlaylistHandler.php`, and
`src/Notification/Application/Handler/SeedDefaultPreferencesHandler.php`.
The Playlist example shows command/handler shape; its unwrapped save-and-dispatch
sequence is not evidence that all producers already have atomic persistence.
