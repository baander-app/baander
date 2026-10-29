---
title: Console JSON output needs real logging handlers in verification
date: 2026-10-29
category: runtime-errors
module: Shared console logging and development library provisioning
problem_type: runtime_error
component: observability
symptoms:
  - "Preview activation fails with Cannot list development libraries: Syntax error"
  - "A successful library-list command writes a Messenger log record before its JSON array"
root_cause: config_error
resolution_type: config_change
severity: medium
tags: [console, json, stdout, stderr, monolog, messenger, preview, test-isolation]
---

# Console JSON output needs real logging handlers in verification

## Problem

Preview startup could reach application and worker readiness, then fail while
reading the library catalog. The command succeeded, but its stdout was not a JSON
document. Tests supplied command output or disabled log handlers, so they did not
exercise the stream that startup consumed.

## Symptoms

The observed startup error was:

```text
Cannot list development libraries: Syntax error
```

Capturing the live `app:library:list --json` process separately showed exit status
zero, empty stderr, and stdout containing a Messenger INFO record followed by `[]`.
The application had no libraries yet; an empty catalog was a valid response.
The consumer decodes the entire stdout string and wraps decoding failures in that
error ([Libraries.php](../../../packages/baander-tooling/src/Development/Libraries.php), lines 21–40).

## What Didn't Work

Passing reconciliation tests did not establish that the real command produced
clean stdout. Their runner supplies JSON strings directly
([DevelopmentLibrariesTest.php](../../../scripts/tests/php/DevelopmentLibrariesTest.php), lines 40–47).
Those tests prove command sequencing and catalog reconciliation; they do not run
the application's logging stack.

The application test environment also differs from the affected environments.
Its Monolog handlers are null handlers, while development and production configure
stream handlers ([monolog.yaml](../../../config/packages/monolog.yaml), lines 42–81 and 98–125).
Even the production OAuth test kernel replaces the `stdout`, `security` and
`messenger` handlers with `NullHandler`
([ProductionOAuthKernel.php](../../../tests/Fixtures/Auth/ProductionOAuthKernel.php), lines 71–75).
A test using that fixture cannot establish where production log records go.

## Solution

Change the stream paths for `stdout`, `security` and `messenger` in both development
and production from `php://stdout` to `php://stderr`: six path changes. Preserve
handler names, levels, formatters and file logging. The handler named `stdout`
retains its name because the OAuth fixture references that service ID; the name
does not determine its stream destination.

Keep strict decoding in the tooling. Filtering log lines out of a command response
would hide the broken output contract and couple the consumer to log formatting.

Verification used real Monolog stream handlers configured from each environment:
Messenger INFO and general/security WARNING records went to stderr while stdout
remained independently parseable JSON. A separate live preview command capture
confirmed the same separation. Startup then created the configured library, and
another startup preserved its identity without starting a scan.

## Why This Works

The console output contract already reserves stdout for the API payload when
`--json` is used
([AdminCommandSupport.php](../../../src/Shared/Interface/Console/AdminCommandSupport.php), lines 24–29).
That contract applies to the whole process, including logs emitted while a query
handler runs. Moving those logs to stderr fixes the producer without weakening
the consumer's JSON validation.

The important distinction is between proving reconciliation against a known
response and proving the command's actual output. A null handler or fabricated
runner result removes the component that caused this failure.

## Prevention

When changing a JSON console command or adding a consumer of one:

1. Capture stdout and stderr separately from a real command process in the
   development configuration. Decode the complete stdout value and check the exit
   status; neither check substitutes for the other.
2. Exercise real development and production stream handlers with Messenger INFO
   and general/security WARNING records. Confirm the records reach stderr while
   stdout remains parseable. A null-handler fixture is not this check.
3. Retain the focused reconciliation tests. Supplement them with the process
   check at the logging boundary rather than replacing their supplied responses
   with assumptions about the runtime.

For a configured worktree preview, run this read-only check from its checkout:

```bash
make exec cmd='php bin/console app:library:list --json' \
  > /tmp/baander-libraries.json 2> /tmp/baander-libraries.log &&
php -r 'json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);' /tmp/baander-libraries.json
```

Inspect the log capture separately. Do not combine the streams before decoding.

## Related Issues

- [Admin and console output contract](../../../.agents/rules/admin-cli-parity.md)
- [Inline Messenger runs must name their routed transport](inline-messenger-run-must-name-the-routed-transport.md)
