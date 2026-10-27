<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling;

use Baander\RegistryTests\BackupContract;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/relay/tests/bootstrap.php';

#[Group('registry-python-subject')]
final class BackupContractTest extends TestCase
{
    public function testUnchangedCoordinatorThroughItsPublicCli(): void
    {
        self::assertGreaterThan(
            80,
            BackupContract::run(
                getenv('REGISTRY_SUBJECT_PYTHON') ?: 'python3',
                dirname(__DIR__, 3) . '/relay/backup/registry_backup.py',
            ),
        );
    }
}
