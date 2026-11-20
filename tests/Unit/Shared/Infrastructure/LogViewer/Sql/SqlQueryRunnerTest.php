<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer\Sql;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Infrastructure\LogViewer\Sql\SqlQueryRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlQueryRunnerTest extends TestCase
{
    public function testCommonTableExpressionsJoinsAndWindows(): void
    {
        $result = new SqlQueryRunner()->run('WITH selected AS (SELECT * FROM logs) SELECT a.level, count(*) AS total, row_number() OVER (ORDER BY a.level) AS position FROM selected a JOIN logs b ON a.id=b.id GROUP BY a.level', [['id' => 'one', 'level' => 'ERROR'], ['id' => 'two', 'level' => 'ERROR']]);
        self::assertSame(['level', 'total', 'position'], $result['columns']);
        self::assertSame([['ERROR', 2, 1]], $result['rows']);
        self::assertFalse($result['truncated']);
    }

    #[DataProvider('unsafeSql')]
    public function testRejectsUnsafeStatements(string $sql): void
    {
        $this->expectException(InvalidInputException::class);
        new SqlQueryRunner()->run($sql, []);
    }

    /** @return iterable<array{string}> */
    public static function unsafeSql(): iterable
    {
        foreach (["SELECT 1\0SELECT 2", 'DELETE FROM logs', 'ATTACH DATABASE \'/tmp/private.db\' AS private', 'PRAGMA database_list', 'SELECT load_extension(\'bad\')', 'SELECT readfile(\'/etc/passwd\')', 'SELECT randomblob(1000000000)', 'SELECT * FROM sqlite_master', 'SELECT 1; SELECT 2', 'WITH x AS (SELECT 1) DELETE FROM logs'] as $sql) {
            yield [$sql];
        }
    }

    public function testSemicolonsInQuotedStringsAndTrailingComments(): void
    {
        self::assertSame([['a;--b', 1]], new SqlQueryRunner()->run("SELECT 'a;--b', 1 /* ; */; -- trailing ;\n /* comment */", [])['rows']);
    }

    public function testTruncationIsExplicit(): void
    {
        $result = new SqlQueryRunner()->run('WITH RECURSIVE x(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM x WHERE n<1001) SELECT n FROM x', []);
        self::assertCount(1000, $result['rows']);
        self::assertTrue($result['truncated']);
    }

    public function testLargeResultsStopWithinOutputBudget(): void
    {
        $result = new SqlQueryRunner()->run('WITH RECURSIVE x(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM x WHERE n<100) SELECT message FROM logs CROSS JOIN x', [['message' => str_repeat('x', 100000)]]);
        self::assertTrue($result['truncated']);
        self::assertLessThan(4194304, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
        self::assertNotEmpty($result['rows']);
    }

    public function testTimeoutCleansPrivateFiles(): void
    {
        $before = glob(sys_get_temp_dir().'/baander-log-sql-*');
        try {
            new SqlQueryRunner(maxSeconds: 0.15)->run('WITH RECURSIVE x(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM x) SELECT sum(n) FROM x', []);
            self::fail('The recursive query must time out.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('timeout', $e->details['reason']);
        }
        self::assertSame($before, glob(sys_get_temp_dir().'/baander-log-sql-*'));
    }

    public function testCancellationCleansPrivateFiles(): void
    {
        $before = glob(sys_get_temp_dir().'/baander-log-sql-*');
        $polls = 0;
        $cancelled = static function () use (&$polls): bool { return ++$polls >= 50; };
        try {
            new SqlQueryRunner(cancelled: $cancelled)->run('WITH RECURSIVE x(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM x) SELECT sum(n) FROM x', []);
            self::fail('The recursive query must be cancelled.');
        } catch (ServiceUnavailableException $error) {
            self::assertSame('cancelled', $error->details['reason']);
        }
        self::assertSame($before, glob(sys_get_temp_dir().'/baander-log-sql-*'));
    }
}
