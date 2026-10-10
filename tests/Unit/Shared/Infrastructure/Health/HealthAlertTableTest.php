<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthAlertRow;
use App\Shared\Infrastructure\Health\HealthAlertState;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use PHPUnit\Framework\TestCase;

final class HealthAlertTableTest extends TestCase
{
    public function testABootedTableStoresRowsAndTheHeartbeatSightingInSharedMemory(): void
    {
        $table = new HealthAlertTable();
        $table->boot();

        self::assertEquals(new HealthAlertRow(), $table->get('redis'));
        self::assertFalse($table->wasSeen());

        $row = new HealthAlertRow(HealthAlertState::Pending, 1_791_633_600, 1_791_633_660);
        self::assertTrue($table->save('redis', $row));
        $table->markSeen();

        self::assertEquals($row, $table->get('redis'));
        self::assertTrue($table->wasSeen());
    }

    public function testForkedWorkersShareTheBootedState(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'The fork check requires pcntl.');
        $table = new HealthAlertTable();
        $table->boot();

        $pid = pcntl_fork();
        if ($pid === 0) {
            $table->save('messenger', new HealthAlertRow(HealthAlertState::Acknowledged, 1_791_633_600));
            $table->markSeen();
            // End the child without running PHPUnit's shutdown handlers.
            posix_kill(getmypid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);

        self::assertEquals(new HealthAlertRow(HealthAlertState::Acknowledged, 1_791_633_600), $table->get('messenger'));
        self::assertTrue($table->wasSeen());
    }

    public function testOutsideTheServerTheStateLivesInThisProcess(): void
    {
        $table = new HealthAlertTable();
        $row = new HealthAlertRow(HealthAlertState::Acknowledged, 1_791_633_600);

        self::assertTrue($table->save('postgresql', $row));
        $table->markSeen();

        self::assertEquals($row, $table->get('postgresql'));
        self::assertTrue($table->wasSeen());
    }
}
