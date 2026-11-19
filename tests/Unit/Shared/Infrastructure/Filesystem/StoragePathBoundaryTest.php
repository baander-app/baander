<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Filesystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StoragePathBoundaryTest extends TestCase
{
    #[DataProvider('publicationCases')]
    public function testConcurrentPublicationPreservesTheBoundary(string $mode, ?string $error, bool $triggered, int $lookups): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/storage-boundary-race-probe.php', $mode]);
        $process->setTimeout(10);
        $process->mustRun();
        $observed = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($triggered, $observed['triggered']);
        self::assertSame($error === null, $observed['resolved']);
        self::assertSame($error, $observed['error']);
        self::assertSame($lookups, $observed['lookups']);
    }

    /** @return iterable<string, array{string, ?string, bool, int}> */
    public static function publicationCases(): iterable
    {
        yield 'new regular file' => ['create', null, true, 2];
        yield 'published regular file' => ['rename', null, true, 2];
        yield 'new escaping link' => ['external-link', 'Path escapes filesystem base path', true, 2];
        yield 'new dangling link' => ['dangling-link', 'Cannot safely resolve filesystem path', true, 2];
        yield 'new non-directory ancestor' => ['non-directory', 'Filesystem path ancestor is not a directory', true, 2];
        yield 'existing escaping link' => ['existing-external-link', 'Path escapes filesystem base path', false, 1];
        yield 'existing dangling link' => ['existing-dangling-link', 'Cannot safely resolve filesystem path', false, 2];
    }
}
