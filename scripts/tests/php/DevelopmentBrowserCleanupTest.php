<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Acceptance\Support;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentBrowserCleanupTest extends TestCase
{
    /** @return iterable<string,array{string,int,bool}> */
    public static function scenarios(): iterable
    {
        yield 'verified removal' => ['removed', 0, false];
        yield 'never created' => ['never-created', 0, false];
        yield 'daemon failure cannot prove absence' => ['daemon-failure', 1, true];
        yield 'surviving container' => ['survivor', 1, true];
        yield 'missing evidence after removal' => ['copy-failure', 1, false];
    }

    #[DataProvider('scenarios')]
    public function testCleanupRequiresVerifiedRemovalAndEvidence(string $scenario, int $status, bool $retained): void
    {
        $root = Support::temporary('baander-browser-cleanup-test-');
        try {
            mkdir($root . '/artifacts');
            $marker = $root . '/artifacts/browser-container';
            file_put_contents($marker, "baander-browser-cleanup-test\n");
            symlink(__DIR__ . '/Fixtures/browser-cleanup-docker.php', $root . '/docker');
            $result = Support::run(
                [
                    'bash',
                    dirname(__DIR__, 2) . '/test-two-command-browser-checkpoint.sh',
                    'cleanup',
                    $root . '/artifacts',
                    '',
                ],
                check: false,
                environment: [
                    'PATH' => $root . PATH_SEPARATOR . getenv('PATH'),
                    'CLEANUP_TEST_SCENARIO' => $scenario,
                    'CLEANUP_TEST_REMOVED' => $root . '/removed',
                ],
            );
            self::assertSame($status, $result->exitCode, $result->stderr);
            self::assertSame($retained, file_exists($marker));
        } finally {
            Support::removeTree($root);
        }
    }
}
