# Concepts

> Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Web server lifecycle

### Drain

The web server's single way of stopping: whatever asked it to stop, it stops taking new connections, lets the work it already has finish within a bounded time, tells clients to come back, and exits on its own.

A drain has a deadline, which an operator configures, and a shorter drain window inside it. During the window, client connections are still open: requests in progress and file downloads finish, real-time connections are closed with a code that tells clients to reconnect, the readiness probe reports not ready, and the liveness probe still answers but says the server is draining. Streams still running when the window closes are cut so that the client sees an error rather than a normal end, which lets a player resume instead of skipping to the next track. A drain runs once however many stop requests arrive. Anything that has not stopped by the deadline is killed, and the server then exits with a failure status.
*Avoid: graceful shutdown (for this process specifically), stop.*

### Worker reload

Replacing the web server's worker processes while the server itself keeps running and keeps its client connections. A reload never starts a drain, and a reload requested during a drain is ignored.
