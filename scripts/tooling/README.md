# Standalone workflow tooling

`bootstrap.php` loads the `Baander\Tooling` namespace without Composer or the
application kernel. Workflow entrypoints call `Runtime\Requirements::check()`
before changing state. The baseline is PHP 8.5 with Swoole. Leaf commands that do
not run processes can pass `false`; commands needing additional extensions pass
their names in the second argument.

`Process\ProcessRunner` owns a CLI dispatch loop and the SIGINT/SIGTERM handlers
during `wait()`. Use it outside application event loops: Swoole cannot return a
previous signal handler for restoration. Multiple processes can start before a
wait, and each wait services all active children. Captured output is returned in
the result. With `logPath`, output streams to a private, truncated file and the
result's output strings are empty. The log directory must be owned by the current
user and must not permit other users to write. Interactive processes inherit the
caller's descriptors.

The filesystem helpers preserve private state with same-directory replacement,
file and directory synchronization, and process-owned locks. They reject symlink
paths. State directories belong to the current user; these checks do not isolate
the files from other processes running as that same user.

Tooling tests load Composer for PHPUnit only. Run them with
`php vendor/bin/phpunit --configuration phpunit-tooling.xml.dist`; static analysis
uses `php vendor/bin/phpstan analyse --configuration phpstan-tooling.neon`.
The `tooling` target in `docker/runner/Dockerfile` provides the pinned runtime
without Python, pcntl or posix for bootstrap and process qualification.
