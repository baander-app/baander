# Admin action with its console command

Read the [admin and console rules](../../../rules/admin-cli-parity.md) first. Use this
recipe when an admin page gains an action, an admin route is added or changed, or a
console command should mirror an API action. Models to read:
`src/Auth/Interface/Console/UserRolesCommand.php` with `AdminUserController` (a write
with `--json`), `src/Library/Interface/Console/LibraryDeleteCommand.php` (a
destructive change with confirmation), and
`src/Lyrics/Interface/Console/LyricsFetchCommand.php` (an inline job).

1. Find or create the use case. When the controller holds the rule, a repository call
   or an aggregate change, move it into an Application command, query or port first,
   then make the controller dispatch it. Report outcomes with `NotFoundException`,
   `ConflictException` and `InvalidInputException` (or a context subclass), so the
   controller needs no try/catch for them.
2. Mark the controller method with `#[CliCounterpart('app:noun:verb')]`, or with
   `#[CliParityExemption(...)]` and a reason. When an admin page calls a non-admin
   route, add its route name to `ADMIN_PAGE_ROUTES` in
   `tests/Integration/AdminCliParityTest.php`.
3. Write the command in the context's `Interface/Console` directory:
   - inject `AdminCommandSupport` and dispatch the same message as the controller;
   - read input with its option helpers, and catch failures with
     `return AdminCommandSupport::fail($io, $e);`;
   - for a destructive change, call `addForceOption()` and `confirm()`, and return
     the code `confirm()` gives when it is not null;
   - call `addJsonOption()`, and on `wantsJson()` print the controller's `data`
     payload with `json()`, built from the same Resource the controller uses. Send
     every other line to `$io->getErrorStyle()`;
   - for long work, run the message through `JobMonitorAdministrationInterface::runInline()`
     and print the stages as they finish. If the handler loops, add
     `JobCancellationCheckpointInterface::check()` between items.
4. Add the docs page from the
   [command template](../../documentation-maintainer/assets/command.md) and a row in
   `docs-book/part-1-operator-guide/commands/README.md`. Document `--force`, `--json`,
   and exit codes 0, 1 and 2 as the command implements them.
5. Test both paths:
   - a unit test of the command with `CommandTester`: the table output, the `--json`
     payload, exit 2 for bad input, exit 1 for an unknown target, and without a
     terminal, exit 2 when `--force` is missing;
   - a functional test that runs the route and the command on the same fixture, and
     shows the same outcome and the same `--json` data as the API.
6. Run `tests/Integration/AdminCliParityTest.php` and `tests/Unit/Docs` alongside the
   focused tests, Deptrac and PHPStan. If the route's status codes or response changed,
   regenerate `openapi.json` with `app:export-openapi-spec` and the web client with
   `yarn generate` in `ui/web`, and check `ui/rn` for callers of the changed route.
