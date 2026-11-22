---
title: Test containers inherit production storage paths from .env.example
date: 2026-11-22
category: developer-experience
module: Backend test coordinator and the test environment (.env.test, packages/baander-dev-ci)
problem_type: test_failure
component: testing_framework
symptoms:
  - "A rate-limited refresh grant at /api/oauth/token answers 503 \"The token endpoint could not complete the grant.\" instead of 429"
  - "Refresh, sign-out, disabled-user and personal access client functional tests fail with \"Cannot create the QoL state directory.\""
  - "An edit to the tracked .env.test has no effect in a container test run until it is committed"
root_cause: config_error
resolution_type: config_change
severity: medium
tags: [env-test, env-example, test-container, convert-storage-path, qol, server-control, test-runner, dotenv]
---

# Test containers inherit production storage paths from .env.example

## Problem

Since the backend test runner stopped using a committed `.env` (2026-11-21, "fix(build): keep local environment files out of source and image builds"), every test container gets a copy of `.env.example`, so tests inherit the production storage paths `/storage/media` and `/storage/conversions`. The test user cannot create them, and any test whose request builds the server control graph fails while a handler is being constructed, with an error that points far away from the cause.

## Symptoms

- `AuthEndpointRateLimitTest::testTheTokenEndpointChargesAnAuthenticatedPublicClientPerCallerNetwork` answers 503 instead of 429.
- `RefreshEndpointTest`, `DisabledUserAccessTest` and `PersonalAccessClientTest` fail with `RuntimeException: Cannot create the QoL state directory.` from `LearningDataPersister`.
- The failure looks like a token-endpoint or rate-limit defect. It is neither.

## What Didn't Work

- Reading the rate-limit path. `OAuthController` maps a client over its allowance to 429 correctly; the 503 came from its catch-all for server faults (`src/Auth/Interface/Controller/OAuth/OAuthController.php:450-455`), which wraps any non-protocol exception.
- Looking for a second message handler. `debug:messenger` shows one handler for the refresh command; the error is raised while that one handler is constructed, not by another handler running after it.
- Overriding the paths in `.env.test` without committing. A run with the edit in the working tree still failed; an instrumented run showed the container's `.env.test` did not contain the new lines. The edit took effect only after it was committed (observed in this session, 2026-11-22).

## Solution

`.env.test` sets both storage roots inside the container's checkout, which the runner hands to the test user:

```dotenv
MEDIA_STORAGE_PATH=/var/www/html/storage/media
CONVERT_STORAGE_PATH=/var/www/html/storage/conversions
```

These are the values the tests passed with before the switch, when the committed `.env` set them.

## Why This Works

The runner extracts the checkout into the application root, copies `.env.example` to `.env`, and gives the tree to `www-data` (`packages/baander-dev-ci/src/Backend/Runtime.php:280-287`). `.env.test` is loaded after `.env` in the test environment, so its values win for anything the container's real environment does not set, and nothing in `compose.test.yaml` sets the storage paths.

The storage path matters to requests that have nothing to do with media because of an eager construction chain. Any handler that can close sockets depends on `ChainConnectionCloser` (`src/Auth/Application/Service/ChainConnectionCloser.php:23`), which depends on `SwooleLiveConnections`, which depends on server control (`src/Shared/Infrastructure/Swoole/SwooleLiveConnections.php:24`). Server control's `ServerControlOperationRegistry` builds every registered operation in its constructor (`src/Shared/Infrastructure/Swoole/Control/ServerControlOperationRegistry.php:16-26`). One operation is the stream governor's learning reset, whose `LearningDataPersister` creates `CONVERT_STORAGE_PATH/qol-state` in its own constructor and throws when it cannot (`src/QoL/Infrastructure/Swoole/LearningDataPersister.php:46-47`, `$stateDir` in `config/services.yaml`). Building a refresh, sign-out or user-disable handler therefore touches the conversions volume.

## Prevention

- Keep every storage root that `.env.example` sets to a production path overridden in `.env.test`. A cheap check would hold this: a unit test that parses both files and fails when a `*_STORAGE_PATH` value in `.env.example` has no `.env.test` override.
- Commit an `.env.test` change before verifying it in the container runner; a working-tree edit to it did not reach the container.
- When a functional test fails with an exception from an unrelated context's constructor, read the full stack trace of the innermost exception (switch the kernel browser to `catchExceptions(false)` in a throwaway edit) before suspecting the code under test.
- Not done here, and worth doing: the same chain means a broken conversions volume in production would also break token refresh, sign-out and user disabling. Creating the QoL state directory on first write, and building server control operations lazily, would remove that coupling.

## Related Issues

- [Coverage runs push the stack lanes past the backend budget](coverage-runs-push-the-stack-lanes-past-the-backend-budget.md): other behavior of the same test coordinator.
