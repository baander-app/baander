# Extracting an application port

Use this recipe only for requested abstraction or boundary work. The previous
ApplicationService migration backlog is completed; discover current concrete
services and their consumers rather than assuming named classes remain to migrate.

Read the full service, callers, existing contracts, and effective wiring. Run
GitNexus impact before changing symbols. Identify whether the abstraction is an
inbound use case or an outbound dependency, and place its interface in the relevant
Application contract area. Do not move business orchestration to Infrastructure
simply because it is called a service.

Prefer an existing appropriate port over a duplicate. Extract the public contract
needed by its consumers, preserving parameter/return types, behavior, and named
argument compatibility. Update implementations and clients together; establish
shared interfaces before parallel edits. Keep implementation location consistent
with its responsibility and context.

Verify all consumers, aliases, decorators, routes/handler registrations, and focused
behavior tests. Remove the old class only after confirming all references and
runtime registrations have moved. Do not use textual find-and-replace for symbol
renames; follow GitNexus rename/refactoring requirements. Missing behavioral intent
may need clarification; an authorized extraction does not require a blanket
confirmation checkpoint before reversible local edits.
