<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

/**
 * File flags that make the production OAuth kernel fail or stall at one point of an
 * issuing transaction, for the concurrency and rollback proofs.
 *
 * A process opts in by naming a directory in the environment variable; without it the
 * injectors are inert, so the acceptance kernels run production code. A flag is a file in
 * that directory; the one-shot flags are deleted when they fire, the block flag stalls
 * the signer until the test deletes it. Child processes get their own directory, so a
 * flag never reaches the wrong process.
 */
final class IssuanceFaults
{
    public const string ENVIRONMENT_VARIABLE = 'BAANDER_TEST_ISSUANCE_FAULT_DIR';

    /** While present, the signer waits inside the issuing transaction. */
    public const string SIGN_BLOCK = 'sign-block';
    /** Written by the signer when it starts waiting, so the test knows the transaction holds its locks. */
    public const string SIGN_BLOCKED = 'sign-blocked';
    /** The next signature fails; the flag is consumed. */
    public const string SIGN_FAIL_ONCE = 'sign-fail-once';
    /** The next commit succeeds on the server and then throws, as a lost acknowledgment does; the flag is consumed. */
    public const string COMMIT_THROW_ONCE = 'commit-throw-once';

    public static function directory(): ?string
    {
        $directory = getenv(self::ENVIRONMENT_VARIABLE);

        return is_string($directory) && $directory !== '' ? $directory : null;
    }

    public static function path(string $directory, string $flag): string
    {
        return $directory . '/' . $flag;
    }

    /** Whether the flag is set right now; the stat cache would otherwise report a deleted flag as present. */
    public static function isSet(string $directory, string $flag): bool
    {
        $path = self::path($directory, $flag);
        clearstatcache(true, $path);

        return is_file($path);
    }

    /** Clears a one-shot flag and reports whether it was set. */
    public static function consume(string $directory, string $flag): bool
    {
        if (!self::isSet($directory, $flag)) {
            return false;
        }
        unlink(self::path($directory, $flag));

        return true;
    }
}
