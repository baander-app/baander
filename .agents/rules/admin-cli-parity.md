# Admin actions and console commands

Every action on the admin pages has a console command, and CLI access is full
authority (user decision, 2026-10-06). Read this before adding or changing an
admin route, an admin page call, or a console command. Read it with the
[architecture rules](architecture-rules.md) and [ports](ddd-ports.md).

## One use case, two entry points

- The controller and its command dispatch the same Application command, query or
  port. Neither may hold a business rule the other lacks; move controller logic into
  Application before adding the command. Admin-created users skipping their default
  notification preferences is the drift this prevents.
- Mark every admin-guarded route, and every non-admin route an admin page calls, on
  the controller method:
  - `#[CliCounterpart('app:noun:verb')]` names the command, or a framework command
    such as `messenger:failed:retry`;
  - `#[CliParityExemption(...)]` records why there is none. The reason is a lasting
    decision that the route needs no command; a reason that defers the command fails
    the parity test, so build the command instead.
- A route counts as admin-guarded when an admin prefix in `access_control` matches it
  or `#[IsGranted]` names an admin role or attribute. Convert inline admin role checks
  to attributes so the test sees them. Owner-or-admin checks are not admin gates.
- A non-admin route that an admin page calls goes on `ADMIN_PAGE_ROUTES` in
  `tests/Integration/AdminCliParityTest.php`. That test fails on an unmarked route, a
  counterpart naming no command, an exemption that defers its command, a marking no
  route reaches, and a counterpart without an operator docs page.

## Commands

- Name commands `app:<noun>[:<sub>]:<verb>` with a singular noun. Rename outliers
  without aliases while the pre-release policy holds.
- Commands act with full authority: no role or admin-setting checks, library reads use
  the unrestricted scope, and audit fields record `Actor::CLI`. A scheduled run that no
  user started records `Actor::SYSTEM`.
- Build commands on `App\Shared\Interface\Console\AdminCommandSupport`:
  - `dispatch()` sends the message and unwraps `HandlerFailedException` through
    `HandlerFailure::cause()`, the same unwrap the HTTP exception subscriber uses;
  - `fail()` prints the message, plus each field's errors for invalid input, on stderr
    and returns the exit code;
  - `confirm()` with `addForceOption()` guards destructive changes: it asks on a
    terminal, and without one it returns exit 2 unless `--force` is given;
  - `integerOption()`, `stringOption()`, `jsonObjectOption()` and `uuid()` read input
    and throw `InvalidInputException`; do not cast options with `(int)`;
  - `list()`, `json()`, `yesNo()` and `prettyJson()` render output.
- Outcomes come from the shared exceptions in `src/Shared/Application/Exception`.
  Throw them from handlers instead of `RuntimeException`, and do not catch them per
  controller:

  | Exception | HTTP | Exit code |
  |-----------|------|-----------|
  | `NotFoundException` | 404 | 1 |
  | `ConflictException` | 409 | 1 |
  | `InvalidInputException` | 422 | 2 |

  Repeating an idempotent action, such as disabling a disabled user, succeeds without
  change. A message clients may see in another language implements
  `TranslatableInterface`; `getMessage()` stays English for the console and logs.
- `--json` prints exactly the `data` payload of the matching API response, on stdout
  only; prompts, progress and errors go to stderr. A command whose route answers 204
  prints nothing and reports through the exit code. A queueing command whose API
  returns no job handle adds the inline run's `jobId`. `--json` never implies `--force`.
- Every command has a page at
  `docs-book/part-1-operator-guide/commands/<name with colons as dashes>.md` and a row
  in that directory's `README.md` index; `CommandDocsCoverageTest` enforces both. Use
  the [command docs guide](../skills/documentation-maintainer/references/commands.md).

## Long-running work

- A command runs long work inline through
  `JobMonitorAdministrationInterface::runInline()`. The run leaves the same job-monitor
  record as the web path. Offer `--queue` only when a process that can do the work
  consumes the message; worker containers may lack the media mounts, and the CLI
  cannot reach `swoole_task`.
- A handler that loops over items calls `JobCancellationCheckpointInterface::check()`
  between items or directories, never per byte or per tight inner step. A cancelled job
  ends acknowledged and `cancelled`, with no retry and nothing in the failure
  transport. Add new long handlers to the table in `app-monitor-job-cancel.md`.
- `swoole_task` is in memory and loses queued messages on restart. State set when
  work is queued there, such as a claim or a `running` status, must expire or be fenced
  by a token the message carries. The library scan claim's lease and claim ID are the
  pattern.
- Guard status transitions in SQL (`WHERE status = :expected`, or a row lock) so a
  late `finished` or `completed` write cannot overwrite a cancellation.

## State inside the running web server

- QoL, server diagnostics and WebSocket connections live in Swoole workers.
  Commands and controllers reach them through `ServerControlPortInterface`. The socket
  client serves commands over the 0600 unix socket; the in-server coordinator serves
  HTTP requests.
- A new operation implements `ServerControlOperation` (tagged
  `app.server_control.operation` automatically). It returns arrays and scalars only,
  must not block, and fans out only when each worker holds its own state; data in a
  shared `Swoole\Table` needs no fan-out.
- A result names every worker that did not answer. A write that missed a worker is a
  partial failure, never a success.
- Do not use Redis pub/sub or any channel the worker container can reach to control
  the web server.
