<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Tests\Support\Doctrine\PgroongaCleanupScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PGroonga keeps a search index's data in Groonga objects outside PostgreSQL's catalog, and a
 * dropped index or throwaway schema leaves them behind. PGroonga's cleanup removes them: `VACUUM`
 * or `ANALYZE` of a table with a PGroonga index runs it, and so do autovacuum and
 * `pgroonga_vacuum()`. Nothing keeps two cleanups apart, and two that run at once collide over the
 * same leftover objects and fail with "failed to remove column". A test that runs a cleanup
 * therefore calls `PgroongaLeftovers::clear()` first. The helper removes the leftovers while it
 * holds locks that autovacuum skips rather than waits for, so the test's own cleanup finds nothing
 * left to collide over. A file whose cleanups reach no table with a PGroonga index is reviewed into
 * the list below instead.
 */
final class PgroongaCleanupConventionTest extends TestCase
{
    /** The helper runs the one cleanup that holds the locks autovacuum skips. */
    private const string HELPER = 'tests/Support/Doctrine/PgroongaLeftovers.php';

    /**
     * Reviewed cleanups in files that do not clear leftovers first: path => number of statements.
     * Add an entry only after checking that the file's cleanups reach no table with a PGroonga
     * index, or never run.
     */
    private const array REVIEWED_CLEANUPS = [
        // `ANALYZE users`; users has no PGroonga index.
        'tests/Functional/Auth/UserEmailLookupTest.php' => 1,
        // `ANALYZE songs` on the test's own copy of songs, made without its PGroonga index.
        'tests/Integration/RetagLockOrderTest.php' => 1,
        // Analyzes five playback tables, none of which has a PGroonga index.
        'tests/Fixtures/Session/playback-snapshot-cost.php' => 1,
        // The fixture below that calls the cleanup function: source this test reads and never runs.
        'tests/Unit/Shared/Infrastructure/Doctrine/PgroongaCleanupConventionTest.php' => 1,
    ];

    private ?string $tree = null;

    protected function tearDown(): void
    {
        if ($this->tree !== null) {
            (new Filesystem())->remove($this->tree);
        }
    }

    public function testEveryCleanupInTheTestsClearsLeftoversFirstOrIsReviewed(): void
    {
        $violations = PgroongaCleanupScanner::scan(dirname(__DIR__, 5), 'tests', self::REVIEWED_CLEANUPS, self::HELPER);

        self::assertSame([], $violations, implode("\n", [
            ...$violations,
            'Clear leftover Groonga objects with PgroongaLeftovers::clear() before the cleanup, or review the file into PgroongaCleanupConventionTest::REVIEWED_CLEANUPS with its count and a reason.',
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function cleanups(): iterable
    {
        yield 'upper-case table analysis' => ["\$connection->executeStatement('ANALYZE songs');"];
        yield 'lower-case table analysis' => ["\$connection->executeStatement('analyze albums');"];
        yield 'table vacuumed and analyzed' => ["\$connection->executeStatement('VACUUM ANALYZE artists');"];
        yield 'cleanup function' => ["\$connection->fetchOne('SELECT pgroonga_vacuum()');"];
        yield 'concatenated table name' => ["\$connection->executeStatement('ANALYZE ' . \$table);"];
    }

    #[DataProvider('cleanups')]
    public function testACleanupWithoutClearingLeftoversFails(string $statement): void
    {
        $violations = PgroongaCleanupScanner::scanSource('tests/Probe.php', "<?php\n" . $statement . "\n");

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringStartsWith('tests/Probe.php:2:', $violations[0]);
        self::assertStringContainsString('PgroongaLeftovers::clear()', $violations[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function statementsWithoutCleanup(): iterable
    {
        yield 'explain with options' => ["\$connection->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT * FROM songs');"];
        yield 'explain analyze' => ["\$connection->fetchAllAssociative('EXPLAIN ANALYZE SELECT * FROM albums');"];
        yield 'CI step name' => ["\$step = ['registry-static-quality', 'Analyze registry and verify direct dependency licenses'];"];
        yield 'comment' => ["// ANALYZE songs\n/** VACUUM albums */\n\$x = 1;"];
    }

    #[DataProvider('statementsWithoutCleanup')]
    public function testAStatementWithoutCleanupPasses(string $statement): void
    {
        self::assertSame([], PgroongaCleanupScanner::scanSource('tests/Probe.php', "<?php\n" . $statement . "\n"));
    }

    public function testAFileThatClearsLeftoversFirstMayRunACleanup(): void
    {
        $cleanup = "\$connection->executeStatement('ANALYZE songs');\n";

        self::assertSame([], PgroongaCleanupScanner::scanSource('tests/Probe.php', "<?php\nPgroongaLeftovers::clear(\$connection);\n" . $cleanup));
        self::assertCount(
            1,
            PgroongaCleanupScanner::scanSource('tests/Probe.php', "<?php\n// PgroongaLeftovers::clear(\$connection);\n" . $cleanup),
            'A helper named only in a comment clears nothing.',
        );
    }

    public function testAReviewedCountCoversExactlyThatManyCleanups(): void
    {
        $once = "<?php\n\$connection->executeStatement('ANALYZE songs');\n";
        $twice = $once . "\$connection->executeStatement('ANALYZE albums');\n";

        self::assertSame([], PgroongaCleanupScanner::scanSource('tests/Probe.php', $once, 1));
        self::assertCount(1, PgroongaCleanupScanner::scanSource('tests/Probe.php', $once), 'An unreviewed cleanup fails.');
        self::assertCount(2, PgroongaCleanupScanner::scanSource('tests/Probe.php', $twice, 1), 'One cleanup more than the reviewed count fails.');
        self::assertCount(1, PgroongaCleanupScanner::scanSource('tests/Probe.php', $once, 2), 'A count above the file\'s cleanups fails.');
    }

    public function testTheScanSkipsTheHelperAndDependenciesAndReportsReviewsOfMissingFiles(): void
    {
        $this->tree = sys_get_temp_dir() . '/pgroonga-cleanup-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $cleanup = "<?php\n\$connection->executeStatement('VACUUM');\n";
        $filesystem->dumpFile($this->tree . '/tests/Support/Doctrine/PgroongaLeftovers.php', $cleanup);
        $filesystem->dumpFile($this->tree . '/tests/node_modules/package/probe.php', $cleanup);
        $filesystem->dumpFile($this->tree . '/tests/Probe.php', $cleanup);

        $violations = PgroongaCleanupScanner::scan($this->tree, 'tests', ['tests/Gone.php' => 1], 'tests/Support/Doctrine/PgroongaLeftovers.php');

        self::assertCount(2, $violations, implode("\n", $violations));
        self::assertStringStartsWith('tests/Probe.php:2:', $violations[0]);
        self::assertStringStartsWith('tests/Gone.php:', $violations[1]);
    }
}
