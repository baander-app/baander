<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class SchedulerContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testOtherContextsMayImplementSchedulerContractsButNotUseSchedulerInternals(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Scheduler\Domain\Model;
interface SchedulableCommandInterface {}
interface SchedulableConsoleCommandInterface {}
trait SchedulerParameterSchema {}
final class InternalSchedulerModel {}
namespace App\Scheduler\Domain\Service;
final class BoundaryRegistry {
    public function register(\App\Scheduler\Domain\Model\SchedulableCommandInterface $command): void {}
}
namespace App\Catalog\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {
    public function internal(\App\Scheduler\Domain\Model\InternalSchedulerModel $model): void {}
}
namespace App\Lyrics\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {
    use \App\Scheduler\Domain\Model\SchedulerParameterSchema;
}
namespace App\Media\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {}
namespace App\Transcode\Application\Command;
final class BoundaryScheduledCommand implements \App\Scheduler\Domain\Model\SchedulableCommandInterface {}
namespace App\Transcode\Interface\Console;
final class BoundaryScheduledConsoleCommand implements \App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface {}
SOURCE);

        self::assertSame(
            ['App\Catalog\Application\Command\BoundaryScheduledCommand must not depend on App\Scheduler\Domain\Model\InternalSchedulerModel'],
            $violations,
        );
    }
}
